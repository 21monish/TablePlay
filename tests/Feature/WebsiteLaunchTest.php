<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class WebsiteLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_utilities_and_contact_form_are_accessible(): void
    {
        $this->get('/')->assertOk()->assertSee('data-site-search', false)
            ->assertSee('data-cookie-banner', false)->assertSee('data-theme-toggle', false)
            ->assertSee('data-back-top', false)->assertSee('og:image', false)->assertSee('/contact');
        $this->get('/contact')->assertOk()->assertSee('data-contact-form', false)
            ->assertSee('data-contact-confirm', false)->assertSee('name="_token"', false);
    }

    public function test_contact_validation_prevents_invalid_input_and_bots(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->post('/contact', ['name'=>"Injected\nHeader", 'email'=>'not-email', 'message'=>'tiny', 'website'=>'bot'])
            ->assertSessionHasErrors(['name', 'email', 'message', 'consent', 'website']);
    }

    public function test_contact_uses_fixed_recipient_and_visitor_only_as_reply_to(): void
    {
        config(['website.support_email'=>'support@example.com', 'mail.default'=>'smtp']);
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $callback) {
            $email = new Email; $callback(new Message($email));
            return str_contains($body, 'Please help set up my restaurant.')
                && $email->getTo()[0]->getAddress() === 'support@example.com'
                && $email->getReplyTo()[0]->getAddress() === 'owner@example.com';
        })->andReturn($this->acceptedMail());
        $this->post('/contact', $this->valid())->assertRedirect('/contact')->assertSessionHas('contact_success');
        $this->get('/contact')->assertSee('accepted by our email service');
    }

    public function test_contact_does_not_claim_delivery_for_log_transport_or_provider_failure(): void
    {
        config(['website.support_email'=>'support@example.com','mail.default'=>'log']);
        $this->post('/contact', $this->valid())->assertStatus(503)->assertSee('could not be sent');
        config(['mail.default'=>'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('private provider error'));
        $this->post('/contact', $this->valid())->assertStatus(503)->assertSee('could not be sent')
            ->assertDontSee('private provider error');
    }

    public function test_contact_rate_limit_stops_repeated_sends(): void
    {
        for ($i=0; $i<3; $i++) $this->post('/contact', [])->assertSessionHasErrors();
        $this->post('/contact', [])->assertStatus(429);
    }

    public function test_contact_escapes_user_content_after_delivery_failure(): void
    {
        config(['mail.default'=>'log']);
        $this->post('/contact', [...$this->valid(), 'message'=>'<script>alert("xss")</script>'])
            ->assertStatus(503)->assertDontSee('<script>alert("xss")</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_static_assets_match_portal_utilities(): void
    {
        foreach (['website.css','website.js'] as $asset) {
            $this->assertSame(file_get_contents(public_path($asset)), file_get_contents(base_path('vercel-site/'.$asset)));
        }
    }

    private function valid(): array
    {
        return ['name'=>'Restaurant Owner','email'=>'owner@example.com','message'=>'Please help set up my restaurant.','consent'=>'1'];
    }

    public function test_cancelled_mail_cannot_report_success(): void
    {
        config(['website.support_email'=>'support@example.com', 'mail.default'=>'smtp']);
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->post('/contact', $this->valid())->assertStatus(503)->assertSee('could not be sent');
    }

    public function test_missing_recipient_cannot_report_success(): void
    {
        config(['website.support_email'=>'', 'mail.default'=>'smtp']);
        Mail::shouldReceive('raw')->never();
        $this->post('/contact', $this->valid())->assertStatus(503)->assertSee('could not be sent');
    }

    private function acceptedMail(): \Illuminate\Mail\SentMessage
    {
        $email = (new Email)->from('mail@example.com')->to('support@example.com')->subject('Accepted')->text('Accepted');
        return new \Illuminate\Mail\SentMessage(new \Symfony\Component\Mailer\SentMessage($email, \Symfony\Component\Mailer\Envelope::create($email)));
    }
}
