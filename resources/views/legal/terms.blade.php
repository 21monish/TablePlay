@extends('legal.layout')

@section('title', 'Terms of Service')
@section('heading', 'Terms of Service')

@section('content')
<p class="legal-intro">These terms govern access to TablePlay. By using the platform, you confirm that you are authorized to act for the restaurant or organization associated with the account.</p>

<section class="legal-section">
    <h2>Service and accounts</h2>
    <p>TablePlay provides restaurant-management, ordering, billing, device-pairing, entertainment, licensing, and related tools. You are responsible for accurate account information, secure credentials, authorized staff access, and activity performed through your account.</p>
</section>

<section class="legal-section">
    <h2>Restaurant responsibilities</h2>
    <p>The restaurant controls the operational and customer data it enters into TablePlay. It must provide legally required notices, use the service lawfully, maintain suitable backups, verify menu and billing information, and protect devices connected to its network.</p>
</section>

<section class="legal-section">
    <h2>Plans, trials, and licences</h2>
    <p>Available features, device limits, support, and subscription duration depend on the selected plan. A free trial is temporary and may expire or lose access to paid features. Activation keys and licences may be used only for the restaurant and installation for which they were issued.</p>
</section>

<section class="legal-section">
    <h2>Acceptable use</h2>
    <p>You must not misuse the service, bypass plan or licence enforcement, probe or disrupt security, upload malicious material, access another organization’s data, or use TablePlay in violation of applicable law.</p>
</section>

<section class="legal-section">
    <h2>Third-party services and availability</h2>
    <p>Cloud features may depend on third-party providers such as Render, Supabase, and Google. Availability can be affected by provider outages, maintenance, network conditions, or free-tier limits. We may modify the service to improve security, reliability, or functionality.</p>
</section>

<section class="legal-section">
    <h2>Ownership, warranties, and liability</h2>
    <p>TablePlay and its software, branding, and documentation remain the property of their respective owner. The service is provided on an “as available” basis to the extent permitted by law. You remain responsible for reviewing orders, totals, tax settings, payments, and business records before relying on them.</p>
</section>

<section class="legal-section">
    <h2>Suspension and termination</h2>
    <p>Access may be suspended or terminated for non-payment, expired licensing, abuse, security risk, or material breach of these terms. Provisions that by their nature should survive termination will continue to apply.</p>
</section>

<section class="legal-section">
    <h2>Contact</h2>
    <p>Questions about these terms can be sent to <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.</p>
</section>
@endsection
