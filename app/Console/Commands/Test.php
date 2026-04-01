<?php

namespace App\Console\Commands;

use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $user = User::first();
        // $firstName = 'safih';
        // $lastName = 'dash';
        // dd(getUserImageInitial($user->id, $firstName . ' ' . $lastName));

        // \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user, '123456'));
        // \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\VerifyEmailMail($user->email, 1234));
        // \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\ForgotPasswordMail($user->email, '1'));

//        $token = 'eTbft-KXrRRlgGJp_2b1Hd:APA91bE6-uty4A6XWn_kgrI8uqRBrVROos_Z3HVBwAqNKVONJvSeHyVcCQkoWNHcGzAMVaGMFS1CFy7tB-5reSvDb7sUV8woDBtvrBks3pUekRa7w2zdfMQ';
//        dispatch(new \App\Jobs\SendFcmNotificationJob($token, "Listing Created", "Your listing Testing has been created successfully."));
    }
}
