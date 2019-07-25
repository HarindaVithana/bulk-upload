<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentImportFailure extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($file)
    {
        $this->user = User::find($file['security_user_id']);
        $this->subject = 'SIS Bulk Upload: Upload Failed' . date('Y:m:d H:i:s');
        $this->from_address = env('MAIL_USERNAME');
        $this->from_name = 'SIS Bulk Uploader';
        $this->with = [
            'name' => $this->user->first_name,
            'link' =>  env('APP_URL').'/download/' .$file['filename']
        ];
        $this->viewData = [
            'name'=>$this->user->first_name, "body" => "We found some errors on your data file ". $file['filename']. ' Pleas fix the errors and re upload it'
        ];
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.mail')
            ->from($this->from_address,$this->from_name)
            ->to($this->user->email)
            ->subject($this->subject)
            ->with($this->with);
    }

}
