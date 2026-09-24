<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditSource;
use App\Enums\PaymentProvider;
use App\Enums\TaxRegime;
use App\Models\Campaign;
use App\Models\OrganizationSetting;
use App\Models\PaymentRequest;
use App\Models\Program;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeScenario;
use App\PublicDonations\OpenPaymentRequest;
use App\PublicDonations\PublicDonationPage;
use App\PublicDonations\PublicDonationStatus;
use App\PublicDonations\StartPublicDonation;
use App\PublicDonations\ValidatePublicDonationForm;
use App\Support\AuditOrigin;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página pública de donativos (Fase 6). Sin lógica de pagos, CFDI ni
 * comunicaciones: valida, guarda lo validado en la sesión del navegador bajo
 * un token aleatorio y llama a StartPublicDonation. El estado que se muestra
 * es el de la base de datos, nunca el de los parámetros de regreso.
 */
class PublicDonationController extends Controller
{
    private const string SESSION = 'public_donations';

    private const string FORM_OPENED_AT = 'public_donation_form_opened_at';

    /** Escenarios del proveedor simulado que la página ofrece (solo local). */
    private const array FAKE_SCENARIOS = [
        'success' => 'Pago aprobado',
        'declined' => 'Tarjeta rechazada',
        'insufficient_funds' => 'Fondos insuficientes',
        'pending' => 'Pago pendiente (se confirma después)',
    ];

    public function __construct(private readonly PublicDonationPage $page) {}

    public function create(Request $request, ?string $campaign = null): View|Response
    {
        $model = $this->campaign($campaign);
        $reason = $this->page->unavailableReason($model, $campaign !== null);
        if ($reason !== null) {
            return response()->view('public.donate.unavailable', $this->shared() + ['message' => $reason], $campaign !== null ? 404 : 503);
        }

        $request->session()->put(self::FORM_OPENED_AT, now()->getTimestamp());

        return view('public.donate.form', $this->shared() + [
            'campaign' => $model,
            'action' => $model !== null ? route('donate.campaign.store', ['campaign' => $model->slug]) : route('donate.store'),
            'suggested' => $this->page->suggestedAmounts(),
            'limits' => $this->page->amountLimits(),
            'acceptsMonthly' => $this->page->acceptsMonthly(),
            'regimes' => collect(TaxRegime::cases())->mapWithKeys(fn (TaxRegime $regime): array => [$regime->value => $regime->value.' — '.$regime->getLabel()])->all(),
        ]);
    }

    public function store(Request $request, ValidatePublicDonationForm $validator, ?string $campaign = null): RedirectResponse|Response
    {
        $model = $this->campaign($campaign);
        if ($this->page->unavailableReason($model, $campaign !== null) !== null) {
            return response()->view('public.donate.unavailable', $this->shared() + ['message' => 'Los donativos no están disponibles en este momento.'], 422);
        }

        if ($this->looksLikeSpam($request)) {
            return back()->withInput()->withErrors(['form' => 'No pudimos procesar el formulario. Revisa los datos e intenta de nuevo.']);
        }

        /** @var array<string, mixed> $input */
        $input = $request->except(['_token', 'website']);
        $payload = $validator->handle($input, $model);

        $token = Str::random(40);
        $this->remember($request, $token, $payload + ['provider' => $this->page->provider()?->value, 'donor_id' => null, 'payment_id' => null, 'subscription_id' => null]);

        return redirect()->route('donate.summary', ['token' => $token]);
    }

    /**
     * Enlace de una solicitud de pago (cobro asistido). Arma el mismo payload
     * que el formulario, con los datos guardados de la solicitud, y sigue el
     * flujo público: resumen, pago con el proveedor y estado.
     */
    public function fromRequest(Request $request, string $token, OpenPaymentRequest $opener): RedirectResponse|Response
    {
        $paymentRequest = PaymentRequest::findByToken($token);
        if ($paymentRequest === null) {
            return response()->view('public.donate.unavailable', $this->shared() + ['message' => 'Este enlace de pago no es válido. Revisa que esté completo o comunícate con la Fundación.'], 404);
        }

        try {
            $payload = $opener->payload($paymentRequest);
        } catch (ValidationException $exception) {
            return response()->view('public.donate.unavailable', $this->shared() + ['message' => collect($exception->errors())->flatten()->first() ?? 'Este enlace de pago no está disponible.'], 410);
        }

        $session = Str::random(40);
        $this->remember($request, $session, $payload);

        return redirect()->route('donate.summary', ['token' => $session]);
    }

    public function summary(Request $request, string $token): View|RedirectResponse
    {
        $payload = $this->payload($request, $token);
        if (PublicDonationStatus::of($payload) !== PublicDonationStatus::NOT_STARTED) {
            return redirect()->route('donate.status', ['token' => $token]);
        }

        $provider = PaymentProvider::tryFrom((string) ($payload['provider'] ?? ''));

        return view('public.donate.summary', $this->shared() + [
            'token' => $token,
            'payload' => $payload,
            'campaign' => $this->campaignById($payload),
            'program' => $this->programById($payload),
            'fromRequest' => isset($payload['payment_request_id']),
            'provider' => $provider,
            'fakeScenarios' => $provider === PaymentProvider::Fake ? self::FAKE_SCENARIOS : [],
            'mercadoPagoPublicKey' => $provider === PaymentProvider::MercadoPago ? config('payments.providers.mercado_pago.public_key') : null,
        ]);
    }

    public function pay(Request $request, string $token, StartPublicDonation $start, AuditOrigin $origin, GatewayRegistry $registry, OpenPaymentRequest $opener): RedirectResponse|View|JsonResponse
    {
        $payload = $this->payload($request, $token);
        $requestId = is_int($payload['payment_request_id'] ?? null) ? $payload['payment_request_id'] : null;
        $provider = PaymentProvider::tryFrom((string) ($payload['provider'] ?? ''));
        if ($provider === null || ! $registry->isEnabled($provider)) {
            return $this->failBack($request, $token, 'Los donativos en línea no están disponibles por ahora.');
        }

        try {
            if ($requestId !== null) {
                $opener->assertPayloadUsable($payload);
            }

            if ($provider === PaymentProvider::Fake && PublicDonationStatus::of($payload) === PublicDonationStatus::NOT_STARTED) {
                $this->applyFakeScenario($registry, $request->string('fake_scenario')->toString());
            }

            $result = $origin->run(AuditSource::Donor, fn (): array => $start->handle(
                $payload,
                $provider,
                $request->filled('card_token') ? $request->string('card_token')->limit(255, '')->toString() : null,
                $request->filled('payment_method_id') ? $request->string('payment_method_id')->limit(50, '')->toString() : null,
            ));
        } catch (ValidationException $exception) {
            return $this->failBack($request, $token, collect($exception->errors())->flatten()->first() ?? 'Revisa los datos e intenta de nuevo.');
        } catch (PaymentProviderException $exception) {
            Log::warning('Página pública: el proveedor no respondió al iniciar el pago.', ['provider' => $provider->value]);

            return $this->failBack($request, $token, 'El servicio de pago no respondió. Intenta de nuevo en unos minutos; no se hará un cargo doble.');
        }

        if ($requestId !== null) {
            $opener->recordStart($requestId, $result['payment'], $result['subscription']);
        }

        $this->remember($request, $token, [
            ...$payload,
            'donor_id' => $result['donor_id'],
            'payment_id' => $result['payment']?->id,
            'subscription_id' => $result['subscription']?->id,
        ]);

        $checkout = $result['checkout'];
        $status = route('donate.status', ['token' => $token]);

        return match (true) {
            $request->expectsJson() => response()->json(['redirect' => $status]),
            $checkout->redirectUrl !== null => redirect()->away($checkout->redirectUrl),
            $provider === PaymentProvider::Stripe && $checkout->clientSecret !== null => view('public.donate.stripe', $this->shared() + [
                'clientSecret' => $checkout->clientSecret,
                'publishableKey' => config('payments.providers.stripe.publishable_key'),
            ]),
            default => redirect()->to($status),
        };
    }

    public function status(Request $request, string $token): View
    {
        $payload = $this->payload($request, $token);
        $state = PublicDonationStatus::of($payload);

        return view('public.donate.status', $this->shared() + [
            'token' => $token,
            'payload' => $payload,
            'state' => $state,
            'campaign' => $this->campaignById($payload),
            'program' => $this->programById($payload),
        ]);
    }

    /**
     * Regreso desde el proveedor. No se confía en sus parámetros: se muestra
     * el estado del último donativo de esta sesión.
     */
    public function returned(Request $request): RedirectResponse|View
    {
        /** @var array<string, array<string, mixed>> $all */
        $all = $request->session()->get(self::SESSION, []);
        $token = array_key_last($all);

        return $token !== null
            ? redirect()->route('donate.status', ['token' => $token])
            : view('public.donate.unavailable', $this->shared() + ['message' => 'No encontramos un donativo en proceso en este navegador. Si ya pagaste, recibirás un correo de confirmación.']);
    }

    /**
     * Reintentar después de un rechazo: mismos datos, pago nuevo (otra llave).
     */
    public function retry(Request $request, string $token, OpenPaymentRequest $opener): RedirectResponse|Response
    {
        $payload = $this->payload($request, $token);
        if (! in_array(PublicDonationStatus::of($payload), [PublicDonationStatus::FAILED, PublicDonationStatus::DECLINED], true)) {
            return redirect()->route('donate.status', ['token' => $token]);
        }

        $new = Str::random(40);
        $paymentRequest = is_int($payload['payment_request_id'] ?? null) ? PaymentRequest::query()->find($payload['payment_request_id']) : null;
        if ($paymentRequest !== null) {
            // Enlace: el intento siguiente de la misma solicitud (misma regla de idempotencia).
            try {
                $this->remember($request, $new, $opener->payload($paymentRequest, retry: true));
            } catch (ValidationException $exception) {
                return response()->view('public.donate.unavailable', $this->shared() + ['message' => collect($exception->errors())->flatten()->first() ?? 'Este enlace de pago no está disponible.'], 410);
            }

            return redirect()->route('donate.summary', ['token' => $new]);
        }

        $this->remember($request, $new, [...$payload, 'idempotency_key' => 'public:'.Str::uuid(), 'payment_id' => null, 'subscription_id' => null]);

        return redirect()->route('donate.summary', ['token' => $new]);
    }

    private function applyFakeScenario(GatewayRegistry $registry, string $scenario): void
    {
        $gateway = $registry->get(PaymentProvider::Fake);
        $value = match ($scenario) {
            'declined' => FakeScenario::Declined,
            'insufficient_funds' => FakeScenario::InsufficientFunds,
            'pending' => FakeScenario::Pending,
            default => FakeScenario::Success,
        };

        if ($gateway instanceof FakeGateway) {
            $gateway->willReturn($value);
        }
    }

    private function looksLikeSpam(Request $request): bool
    {
        $openedAt = $request->session()->get(self::FORM_OPENED_AT);

        return $request->filled('website')
            || ! is_int($openedAt)
            || now()->getTimestamp() - $openedAt < config()->integer('donations.public.min_seconds_to_submit');
    }

    private function failBack(Request $request, string $token, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : redirect()->route('donate.summary', ['token' => $token])->withErrors(['payment' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $token): array
    {
        $payload = $request->session()->get(self::SESSION.'.'.$token);
        abort_unless(is_array($payload), 404);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Guarda en la sesión (solo las últimas 5 operaciones de este navegador).
     *
     * @param  array<string, mixed>  $payload
     */
    private function remember(Request $request, string $token, array $payload): void
    {
        /** @var array<string, array<string, mixed>> $all */
        $all = $request->session()->get(self::SESSION, []);
        unset($all[$token]);
        $all[$token] = $payload;
        $request->session()->put(self::SESSION, array_slice($all, -5, null, true));
    }

    /**
     * Solo colores #RGB o #RRGGBB: nada de la configuración llega sin validar al CSS.
     */
    private static function hexColor(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : $default;
    }

    private function campaign(?string $slug): ?Campaign
    {
        return $slug !== null ? Campaign::query()->with('program')->where('slug', $slug)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function campaignById(array $payload): ?Campaign
    {
        return is_int($payload['campaign_id'] ?? null) ? Campaign::query()->find($payload['campaign_id']) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function programById(array $payload): ?Program
    {
        return is_int($payload['program_id'] ?? null) ? Program::query()->find($payload['program_id']) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $settings = OrganizationSetting::current();

        return [
            'organization' => Branding::name(),
            'logoUrl' => Branding::logoUrl(),
            'faviconUrl' => Branding::faviconUrl(),
            'privacyUrl' => $settings->privacy_notice_url,
            'privacyVersion' => $settings->privacy_notice_version,
            'colors' => [
                'primary' => self::hexColor(config('donations.public.colors.primary'), '#162562'),
                'secondary' => self::hexColor(config('donations.public.colors.secondary'), '#F2C94C'),
            ],
        ];
    }
}
