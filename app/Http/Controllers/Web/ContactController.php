<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    public function create()
    {
        return view('contact');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'email:rfc', 'max:255', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'consent' => ['accepted'],
            'website' => ['prohibited'],
        ]);
        $recipient = trim((string) config('website.support_email'));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !\App\Services\OutboundMailSafety::canDeliver()) {
            return $this->unavailable($request);
        }
        try {
            $sent = Mail::raw("Website enquiry\n\nName: {$data['name']}\nEmail: {$data['email']}\n\n{$data['message']}", function ($mail) use ($recipient, $data) {
                $mail->to($recipient)->replyTo($data['email'], $data['name'])->subject('TablePlay website enquiry');
            });
            if ($sent === null) {
                return $this->unavailable($request);
            }
        } catch (Throwable) {
            // Do not log message bodies, credentials or provider errors containing personal data.
            return $this->unavailable($request);
        }
        return redirect()->route('contact')->with('contact_success', 'Your message was accepted by our email service. We will reply to the email address you provided.');
    }

    private function unavailable(Request $request)
    {
        return response()->view('contact', [
            'deliveryError' => 'Your message could not be sent. Please try again later. Your enquiry has not been confirmed.',
            'contactValues' => $request->only(['name', 'email', 'message']),
        ], 503);
    }
}
