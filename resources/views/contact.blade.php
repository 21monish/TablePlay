<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact TablePlay</title>
    <meta name="description" content="Ask TablePlay about restaurant setup, free trials and staff or customer applications.">
    <link rel="canonical" href="{{ route('contact') }}">
    <link rel="stylesheet" href="/website.css"><script src="/website.js" defer></script>
    <style>body{margin:0;font:16px/1.6 system-ui,sans-serif;background:#f3f6f3;color:#10231f}a{color:inherit}header{padding:16px 24px;border-bottom:1px solid #b3c4bb;position:sticky;top:0;background:inherit;z-index:20}</style>
</head>
<body>
<a class="site-skip" href="#main-content">Skip to content</a>
<header><a href="{{ route('portal') }}">TablePlay / Home</a></header>
<main id="main-content" class="site-contact" tabindex="-1">
    <p>RESTAURANT SERVICE, CONNECTED</p><h1>Let's talk about your restaurant.</h1>
    <p>Ask about TablePlay, setup or your trial. Never include passwords, licence keys or payment details.</p>
    @if(session('contact_success'))<p class="site-feedback" role="status">{{ session('contact_success') }}</p>@endif
    @if(isset($deliveryError))<p class="site-feedback site-feedback--error" role="alert">{{ $deliveryError }}</p>@endif
    @if($errors->any())<div class="site-feedback site-feedback--error" role="alert"><p>Please correct the following:</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="{{ route('contact.store') }}" data-contact-form>
        @csrf
        <label>Name<input name="name" autocomplete="name" maxlength="100" required value="{{ $contactValues['name'] ?? old('name') }}" @if($errors->has('name')) aria-invalid="true" @endif></label>
        <label>Email<input type="email" name="email" autocomplete="email" maxlength="255" required value="{{ $contactValues['email'] ?? old('email') }}" @if($errors->has('email')) aria-invalid="true" @endif></label>
        <label>Message<textarea name="message" rows="6" minlength="10" maxlength="3000" required @if($errors->has('message')) aria-invalid="true" @endif>{{ $contactValues['message'] ?? old('message') }}</textarea></label>
        <input class="site-honeypot" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" aria-label="Leave empty">
        <label class="site-check"><input type="checkbox" name="consent" value="1" required> <span>I agree to the <a href="{{ route('privacy') }}">Privacy Policy</a> and to being contacted about this enquiry.</span></label>
        <button class="site-button" type="submit">Send message</button>
    </form>
    <p>Last updated: <time datetime="2026-10-18">18 October 2026</time></p>
</main>
<dialog class="site-dialog" data-contact-confirm aria-labelledby="contact-confirm-title"><h2 id="contact-confirm-title">Send your enquiry?</h2><p>Your name, email and message will be sent to TablePlay support.</p><div class="site-tools"><button type="button" data-cancel-send autofocus>Go back</button><button type="button" data-confirm-send>Send message</button></div></dialog>
<span class="site-status" data-site-status role="status" aria-live="polite"></span>
</body></html>
