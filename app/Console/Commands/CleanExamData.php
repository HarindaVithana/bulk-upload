<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Examination_student;
use App\Models\Institution_class_student;
use App\Models\Institution_student;
use App\Models\Institution_student_admission;
use App\Models\Security_user;
use Illuminate\Support\Facades\Artisan;

class CleanExamData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'examination:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean SIS data duplication after Exam import';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();


    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
           DB::table('institution_student as is')
           ->join('security_users as su','su.id','is.student_id')
           ->where('updated_from','doe')
            ->chunk($this->argument('limit'),function($Students){
                foreach ($Students as $Student) {
                    $exist = Examination_student::where('nsid',$Student->openemis_no)->exist();
                    if(!$exist){
                        Institution_student::where('student_id',$Student->student_id)->delete();
                        Institution_class_student::where('student_id',$Student->student_id)->delete();
                        Institution_student_admission::where('student_id',$Student->student_id)->delete();
                        Security_user::where('id',$Student->student_id)->delete();
                    }
                }
            });  
    }
}
