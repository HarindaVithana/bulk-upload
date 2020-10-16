<?php

namespace App\Console\Commands;

use App\Models\Institution;
use Illuminate\Console\Command;
use App\Models\Institution_class;
use Illuminate\Support\Facades\DB;
use App\Models\Institution_class_student;
use App\Models\Institution_student_admission;

class UpdateClassEntriyWithZeroId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:zero_id_class';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'update student class reference';

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
        DB::statement("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
        $students = Institution_class_student::whereRaw('institution_class_id not in (select id from institution_classes)')
            ->orWhere('institution_class_id',0)
            ->get()->toArray();
        if(count($students)>0){
            processParallel(array($this,'process'),$students,15);
        }else{
            echo "all are updated \r\n";
        }
    }

    public function process($student){
        $institutionClass =  Institution_class::getGradeClasses($student['education_grade_id'],$student['institution_id']);
        
        if(count($institutionClass) == 1){
            Institution_class_student::where('student_id',$student['student_id'])
            ->update(['institution_class_id' => $institutionClass[0]['id'],'education_grade_id' => $student['education_grade_id']]); 
            $studentAdmission = Institution_student_admission::where('student_id',$student['student_id'])
            ->get()->toArray();
            if(!is_null($studentAdmission) && count($studentAdmission) == 1 ){
                Institution_student_admission::where('student_id',$student['student_id'])
                ->update(['institution_class_id' => $institutionClass[0]['id'],'education_grade_id' => $student['education_grade_id']]); 
            }elseif(count($studentAdmission)==0){
                Institution_student_admission::create(
                    [
                        'student_id'=>$student['student_id'],
                        'institution_class_id'=>  $institutionClass[0]['id'],
                        'education_grade_id' => $student['education_grade_id'],
                        'institution_id' => $student['institution_id'],
                        'status_id' => 124,
                        'academic_period_id' => $student['academic_period_id'],
                        'created_user_id' => $student['created_user_id']
                    ]
                );
            }
            echo "updated:" .$student['student_id']; 
        }else{
            Institution_class_student::where('student_id',$student['student_id'])->delete();
        }
    }
}
