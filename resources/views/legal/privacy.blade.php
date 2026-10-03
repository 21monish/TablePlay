@extends('legal.layout')

@section('title', 'Privacy Policy')
@section('heading', 'Privacy Policy')

@section('content')
<p class="legal-intro">This policy explains how TablePlay handles information when you use its restaurant-management platform, local applications, website, and account services.</p>

<section class="legal-section">
    <h2>Information we process</h2>
    <ul>
        <li>Account details such as name, email address, role, and authentication records.</li>
        <li>Restaurant information, subscription and licence records, device registrations, and support requests.</li>
        <li>Operational data entered by a restaurant, including menus, tables, orders, bills, and staff assignments.</li>
        <li>Technical information needed for security and reliability, such as timestamps, IP addresses, and application logs.</li>
    </ul>
</section>

<section class="legal-section">
    <h2>How information is used</h2>
    <p>We use information to provide and secure TablePlay, authenticate users, operate licences and subscriptions, deliver service messages, diagnose errors, and support restaurant operations. We do not sell personal information.</p>
</section>

<section class="legal-section">
    <h2>Google user data and Gmail API</h2>
    <p>TablePlay requests only the <strong>gmail.send</strong> permission for the Google account connected by the platform administrator. This access is used solely to send transactional TablePlay messages, such as account-verification emails, from that account.</p>
    <p class="legal-note">TablePlay cannot use this permission to read, modify, or delete inbox messages. Google OAuth credentials are kept in protected deployment secrets and are not committed to the source repository.</p>
</section>

<section class="legal-section">
    <h2>Service providers</h2>
    <p>TablePlay may use infrastructure providers including Render for application hosting, Supabase for managed database services, and Google for authorized email delivery. Each provider processes data under its own security and privacy terms.</p>
</section>

<section class="legal-section">
    <h2>Retention, security, and deletion</h2>
    <p>Information is retained only while needed to provide the service, comply with legal obligations, resolve disputes, and protect the platform. Reasonable technical and organizational safeguards are used, but no internet service can guarantee absolute security.</p>
    <p>Account owners may request correction or deletion of their personal information by contacting the address below. Some records may be retained when legally required or needed for fraud prevention and system integrity.</p>
</section>

<section class="legal-section">
    <h2>Children and policy changes</h2>
    <p>TablePlay is a business service and is not directed to children. We may update this policy as the service changes; the effective date above identifies the current version.</p>
</section>

<section class="legal-section">
    <h2>Contact</h2>
    <p>For privacy questions or data requests, email <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.</p>
</section>
@endsection
