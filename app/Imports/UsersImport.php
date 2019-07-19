<?php

namespace App\Imports;

use App\Models\Education_grades_subject;
use App\Models\Institution_class_student;
use App\Models\Institution_class_subject;
use App\Models\Institution_student_admission;
use App\Models\Institution_subject;
use App\Models\Institution_subject_student;
use App\Models\Security_user;
use App\Models\User_body_mass;
use App\Models\Institution_student;
use App\Models\Import_mapping;
use App\Models\Nationality;
use App\Models\Identity_type;
use App\Models\Student_guardian;
use App\Models\Academic_period;
use App\Models\Institution_class;
use App\Models\Institution_class_grade;
use App\Models\Area_administrative;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use Webpatser\Uuid\Uuid;


class UsersImport implements ToCollection , WithStartRow  , WithHeadingRow , WithMultipleSheets , WithEvents , WithMapping , WithBatchInserts
{
    use Importable , RegistersEventListeners;

    public function __construct()
    {
        $this->sheetNames = [];
        $this->sheetData = [];
        $this->request = new Request;
    }


    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */



    public function sheets(): array
    {
        return [
            // Select by sheet index
//                0 => $this->sheetData,
            1 => $this

        ];
    }



    public function batchSize(): int
    {
        // TODO: Implement batchSize() method.
        return 100;
    }

    public function registerEvents(): array
    {
        // TODO: Implement registerEvents() method.
        return [
            BeforeSheet::class => function(BeforeSheet $event){
                $this->sheetNames[] = $event->getSheet()->getTitle();
            }
        ];
    }

    public function startRow(): int
    {
       return 3;
    }





    public function headingRow(): int
    {
        return 2;
    }


    /**
     * @return array
     */
    public function getSheetNames(): array
    {
        return $this->sheetNames;
    }

    /**
     * @return array
     */
    public function getSheetData(): array
    {
        return $this->sheetData;
    }



    /**
     * @param mixed $row
     * @return array
     * @throws \Exception
     */
    public function map($row): array
    {

        try{
            $BirthArea = Area_administrative::where('name', 'like', '%'.$row['birth_registrar_office_as_in_birth_certificate'].'%')->first();

            if(gettype($row['date_of_birth_yyyy_mm_dd']) == 'double'){
                $row['date_of_birth_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['date_of_birth_yyyy_mm_dd']);
            }

            if(gettype($row['bmi_date_yyyy_mm_dd']) == 'double'){
                $row['bmi_date_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['bmi_date_yyyy_mm_dd']);
            }

            if(gettype($row['start_date_yyyy_mm_dd']) == 'double'){
                $row['start_date_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['start_date_yyyy_mm_dd']);
            }

            if(gettype($row['fathers_date_of_birth_yyyy_mm_dd']) == 'double'){
                $row['fathers_date_of_birth_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['fathers_date_of_birth_yyyy_mm_dd']);
            }

            if(gettype($row['mothers_date_of_birth_yyyy_mm_dd']) == 'double'){
                $row['mothers_date_of_birth_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['mothers_date_of_birth_yyyy_mm_dd']);
            }

            if(gettype($row['guardians_date_of_birth_yyyy_mm_dd']) == 'double'){
                $row['guardians_date_of_birth_yyyy_mm_dd'] = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['guardians_date_of_birth_yyyy_mm_dd']);
            }

            if($row['identity_type'] == 'BC'){
                $row['identity_number'] = $BirthArea->id . '' . $row['identity_number'] . '' . substr($row['date_of_birth_yyyy_mm_dd']->format("yy"), -2) . '' . $row['date_of_birth_yyyy_mm_dd']->format("m");
            }


        }catch (\Exception $e){
            \Log::error('Import Error',[$e]);
            Redirect::back()->withErrors(['Template is not valid, pleas upload the correct template']);
        }

        return $row;

    }

    public  function array(array $array){
        $this->sheetData[] = $array;
    }


    /**
     * @param array $options
     * @return string
     */
    public static  function getUniqueOpenemisId($options = [])
    {
        $prefix = '';

        $prefix =  DB::table('config_items')->where('code','=','openemis_id_prefix')->get();
        $prefix = explode(",", $prefix);
        $prefix = ($prefix[1] > 0) ? $prefix[0] : '';

        $latest = Security_user::orderBy('id', 'DESC')
            ->first();

        if (is_array($latest)) {
            $latestOpenemisNo = $latest['SecurityUser']['openemis_no'];
        } else {
            $latestOpenemisNo = $latest->openemis_no;
        }
        if (empty($prefix)) {
            $latestDbStamp = $latestOpenemisNo;
        } else {
            $latestDbStamp = substr($latestOpenemisNo, strlen($prefix));
        }

        $currentStamp = time();
        if ($latestDbStamp >= $currentStamp) {
            $newStamp = $latestDbStamp + 1;
        } else {
            $newStamp = $currentStamp;
        }

        return $prefix . $newStamp;
    }




    public function validateClassRoom(){
        if(!empty(Auth::user()->permissions[0]->security_group_institution->staff_class)){
            Redirect::back()->withErrors(['You don\' have classes to import data to any Class Rooms ']);
        }
    }


    public function collection(Collection $rows)
    {

        dd($rows);

       $institutionClassId = Input::get('class');
       $institutionClass = Institution_class::find($institutionClassId);
       $institution = $institutionClass->institution_id;

       $totalMaleStudents = $institutionClass->total_male_students;
       $totalFemaleStudents = $institutionClass->total_female_students;
       $totalStudents = $totalMaleStudents + $totalFemaleStudents;

       if(($totalStudents + count($rows)) > $institutionClass->no_of_students){
           return Redirect::back()->withErrors(['The number of students in '.$institutionClass->name.' is grater than student count.','Current Student count is '. $institutionClass->no_of_students.'.' ]);
       }

       $maleStudentsCount = 0;
       $femaleStudentsCount = 0;

       if(!empty($institutionClass)){


           $institutionGrade = Institution_class_grade::where('institution_class_id','=',$institutionClass->id)->first();
           $mandatorySubject = Institution_class_subject::with(['institutionMandatorySubject'])
               ->whereHas('institutionMandatorySubject',function ($query) use ($institutionGrade) {
                   $query->where('education_grade_id','=',$institutionGrade->education_grade_id);
                })
               ->where('institution_class_id','=',$institutionClass->id)
               ->get()->toArray();
           $subjects =  getMatchingKeys($rows[0]) ;

           $this->validateRow($rows);
           foreach ($rows as $row) {


               $genderId = $row['gender_mf'] == 'M' ? 1 : 2;
               switch ($row['gender_mf']){
                   case 'M':
                       $maleStudentsCount += 1;
                       break;
                   case 'F':
                       $femaleStudentsCount += 1;
                       break;
               }

//            $AddressArea = Area_administrative::where('name', 'like', '%'.$row['address_area'].'%')->first();
               $BirthArea = Area_administrative::where('name', 'like', '%'.$row['birth_registrar_office_as_in_birth_certificate'].'%')->first();
               $nationalityId = Nationality::where('name','like','%'.$row['nationality'].'%')->first();
               $identityType = Identity_type::where('national_code','like','%'.$row['identity_type'].'%')->first();
               $academicPeriod = Academic_period::where('name', '=',$row['academic_period'])->first();

//                dd($row);

               $date = $row['date_of_birth_yyyy_mm_dd'];


               $identityNUmber = $row['identity_number'];

               $openemisStudent = $this::getUniqueOpenemisId();



               \Log::debug('Security_user');
               $student =  Security_user::create([
                   'username'=> $openemisStudent,
                   'openemis_no'=>$openemisStudent,
                   'first_name'=> $row['full_name'], // here we save full name in the column of first name. re reduce breaks of the system.
                   'last_name' => genNameWithInitials($row['full_name']),
                   'gender_id' => $genderId,
                   'date_of_birth' => $date ,
                   'address'   => $row['address'],
//                'address_area_id'   => $AddressArea->id,
                   'birthplace_area_id' => $BirthArea->id,
                   'nationality_id' => $nationalityId->id,
                   'identity_type_id' => $identityType->id,
                   'identity_number' => $identityNUmber ,
                   'is_student' => 1
               ]);


               Institution_student_admission::create([
                   'start_date' => $row['start_date_yyyy_mm_dd'],
                   'start_year' => $row['start_date_yyyy_mm_dd']->format('Y'),
                   'end_date' => $academicPeriod->end_date,
                   'end_year' =>  $academicPeriod->end_year,
                   'student_id' => $student->id,
                   'status_id' => 1,
                   'assignee_id' => 1,
                   'institution_id' => $institution,
                   'academic_period_id' => $academicPeriod->id,
                   'education_grade_id' => $institutionGrade->education_grade_id,
                   'institution_class_id' => $institutionClass->id,
                   'comment' => 'Imported',
                   'admission_id' => $row['admission_no'],
               ]);

               \Log::debug('Institution_student');
               Institution_student::create([
                   'student_status_id' => 1,
                   'student_id' => $student->id,
                   'education_grade_id' => $institutionGrade->education_grade_id,
                   'academic_period_id' => $academicPeriod->id,
                   'start_date' => $row['start_date_yyyy_mm_dd'],
                   'start_year' => $row['start_date_yyyy_mm_dd']->format('Y'),
                   'end_date' => $academicPeriod->end_date,
                   'end_year' =>  $academicPeriod->end_year,
                   'institution_id' => $institution,
                   'admission_id' => $row['admission_no']
               ]);

               // convert Meeter to CM
               $hight = $row['bmi_height']/100;

               //calculate BMI
               $bodyMass = ($row['bmi_weight']) / pow($hight,2);

//            dd($row);

               $bmiAcademic = Academic_period::where('name', '=', $row['bmi_academic_period'])->first();

               \Log::debug('User_body_mass');
               User_body_mass::create([
                   'height' => $row['bmi_height'],
                   'weight' => $row['bmi_weight'],
                   'date' => $row['bmi_date_yyyy_mm_dd'],
                   'body_mass_index' => $bodyMass,
                   'academic_period_id' => $bmiAcademic->id,
                   'security_user_id' => $student->id
               ]);

               //import father's information
               if(!empty($row['fathers_full_name'])){
                   $AddressArea = Area_administrative::where('name', 'like', '%'.$row['fathers_address_area'].'%')->first();
                   $nationalityId = Nationality::where('name','like','%'.$row['fathers_nationality'].'%')->first();
                   $identityType = Identity_type::where('national_code','like','%'.$row['fathers_identity_type'].'%')->first();
                   $openemisFather = $this::getUniqueOpenemisId();

                   $father = Security_user::where('identity_type_id','=', $nationalityId->id)
                       ->where('identity_number' , '=', $row['fathers_identity_number'])->first();

                   if(empty($father)){
                       $father  =   Security_user::create([
                           'username'=> $openemisFather,
                           'openemis_no'=>$openemisFather,
                           'first_name'=> $row['fathers_full_name'], // here we save full name in the column of first name. re reduce breaks of the system.
                           'last_name' => genNameWithInitials($row['fathers_full_name']),
                           'gender_id' => 1,
                           'date_of_birth' => $row['fathers_date_of_birth_yyyy_mm_dd'] ,
                           'address'   => $row['fathers_address'],
                           'address_area_id'   => $AddressArea->id,
                           'birthplace_area_id' => $BirthArea->id,
                           'nationality_id' => $nationalityId->id,
                           'identity_type_id' => $identityType->id,
                           'identity_number' => $row['fathers_identity_number'] ,
                           'is_guardian' => 1
                       ]);
                       $father['guardian_relation_id'] = 1;
                       Student_guardian::createStudentGuardian($student,$father);
                   }else{
                       Security_user::where('identity_number' , '=', $row['fathers_identity_number'])
                           ->update(['is_guardian' => 1]);
                       $father['guardian_relation_id'] = 1;
                       Student_guardian::createStudentGuardian($student,$father);
                   }
               }

               if(!empty($row['mothers_full_name'])){
                   $AddressArea = Area_administrative::where('name', 'like', '%'.$row['mothers_address_area'].'%')->first();
                   $nationalityId = Nationality::where('name','like','%'.$row['mothers_nationality'].'%')->first();
                   $identityType = Identity_type::where('national_code','like','%'.$row['mothers_identity_type'].'%')->first();
                   $openemisMother = $this::getUniqueOpenemisId();

                   $mother = Security_user::where('identity_type_id','=', $nationalityId->id)
                       ->where('identity_number' , '=', $row['mothers_identity_number'])->first();

                   if(empty($mother)){
                       $mother = Security_user::create([
                           'username'=> $openemisMother,
                           'openemis_no'=>$openemisMother,
                           'first_name'=> $row['mothers_full_name'], // here we save full name in the column of first name. re reduce breaks of the system.
                           'last_name' => genNameWithInitials($row['mothers_full_name']),
                           'gender_id' => 2,
                           'date_of_birth' => $row['mothers_date_of_birth_yyyy_mm_dd'] ,
                           'address'   => $row['mothers_address'],
                           'address_area_id'   => $AddressArea->id,
                           'birthplace_area_id' => $BirthArea->id,
                           'nationality_id' => $nationalityId->id,
                           'identity_type_id' => $identityType->id,
                           'identity_number' => $row['mothers_identity_number'] ,
                           'is_guardian' => 1
                       ]);
                       $mother['guardian_relation_id'] = 1;
                       Student_guardian::createStudentGuardian($student,$mother);
                   }else{
                       Security_user::where('identity_number' , '=', $row['mothers_identity_number'])
                           ->update(['is_guardian' => 1]);
                       $mother['guardian_relation_id'] = 2;
                       Student_guardian::createStudentGuardian($student,$mother);
                   }
               }

               if(!empty($row['guardians_full_name'])){
                   $genderId = $row['guardians_gender_mf'] == 'M' ? 1 : 2;
                   $AddressArea = Area_administrative::where('name', 'like', '%'.$row['guardians_address_area'].'%')->first();
                   $nationalityId = Nationality::where('name','like','%'.$row['guardians_nationality'].'%')->first();
                   $identityType = Identity_type::where('national_code','like','%'.$row['guardians_identity_type'].'%')->first();
                   $openemisGuardian = $this::getUniqueOpenemisId();

                   $guardian = Security_user::where('identity_type_id','=', $nationalityId->id)
                       ->where('identity_number' , '=', $row['guardians_identity_number'])->first();

                   if(empty($guardian)){
                       $guardian =  Security_user::create([
                           'username'=> $openemisGuardian,
                           'openemis_no'=>$openemisGuardian,
                           'first_name'=> $row['guardians_full_name'], // here we save full name in the column of first name. re reduce breaks of the system.
                           'last_name' => genNameWithInitials($row['guardians_full_name']),
                           'gender_id' => $genderId,
                           'date_of_birth' => $row['guardians_date_of_birth_yyyy_mm_dd'] ,
                           'address'   => $row['guardians_address'],
                           'address_area_id'   => $AddressArea->id,
                           'birthplace_area_id' => $BirthArea->id,
                           'nationality_id' => $nationalityId->id,
                           'identity_type_id' => $identityType->id,
                           'identity_number' => $row['guardians_identity_number'] ,
                           'is_guardian' => 1
                       ]);
                       $guardian['guardian_relation_id'] = 1;
                       Student_guardian::createStudentGuardian($student,$guardian);
                   }else{
                       Security_user::where('identity_number' , '=', $row['guardians_identity_number'])
                           ->update(['is_guardian' => 1]);
                       $guardian['guardian_relation_id'] = 1;
                       Student_guardian::createStudentGuardian($student,$guardian);
                   }
               }



               $student = Institution_class_student::create([
                   'student_id'  => $student->id,
                   'institution_class_id' => $institutionClass->id,
                   'education_grade_id' => $institutionGrade->education_grade_id,
                   'academic_period_id'=>$academicPeriod->id,
                   'institution_id' => $institution,
                   'student_status_id' => 1
               ]);


               //Option subject feed
               $optionalSubjects = $this->getStudentOptionalSubject($subjects,$student,$row,$institution);

               $allSubjects = array_merge_recursive($optionalSubjects,$mandatorySubject);
               if(!empty($allSubjects)){


                   $allSubjects = unique_multidim_array($allSubjects,'institution_subject_id');
                   $allSubjects = $this->setStudentSubjects($allSubjects,$student);
//                   $allSubjects = array_unique($allSubjects,SORT_REGULAR);
                   $allSubjects = unique_multidim_array($allSubjects,'education_subject_id');

                   Institution_subject_student::insert((array) $allSubjects);
               }

               unset($allSubjects);

               $total_male_students = $totalMaleStudents + $maleStudentsCount;
               $total_female_students = $totalFemaleStudents + $femaleStudentsCount;

                Institution_class::where('id','=',$institutionClass->id)
                    ->update([
                        'total_male_students' => $total_male_students ,
                        'total_female_students' => $total_female_students ]);

           }


       }else{
           return Redirect::back()->withErrors(['The class '.$this->sheetNames[0].' not found in your school']);
       }


    }


    /**
     * @param $subjects
     * @param $student
     * @return array
     * @throws \Exception
     */
    public  function  setStudentSubjects($subjects,$student){
        $data = [];

        foreach ($subjects as $subject){
            $educationSubjectId =  key_exists('institution_optional_subject',$subject) ? $subject['institution_optional_subject']['education_subject_id'] : $subject['institution_mandatory_subject']['education_subject_id'];


                $data[]  = [
                    'id' => (string) Uuid::generate(4),
                    'student_id' => $student->student_id,
                    'institution_class_id' => $student->institution_class_id,
                    'institution_subject_id' => $subject['institution_subject_id'],
                    'institution_id' => $student->institution_id,
                    'academic_period_id' => $student->academic_period_id,
                    'education_subject_id' => $educationSubjectId,
                    'education_grade_id' => $student->education_grade_id,
                    'student_status_id' => 1,
                    'created_user_id' => Auth::user()->id,
                    'created' => now()
                ];

        }
        return $data;
    }

    public function getStudentOptionalSubject($subjects,$student,$row,$institution){
        $data = [];


        foreach ($subjects as $subject){

            $subjectId = Institution_class_subject::with(['institutionOptionalSubject'])
                ->whereHas('institutionOptionalSubject',function ($query) use ($row,$subject,$student){
                $query->where('name','=',$row[$subject])
                    ->where('education_grade_id','=',$student->education_grade_id);
                })
                ->where('institution_class_id','=',$student->institution_class_id)
                ->get()->toArray();
                if(!empty($subjectId))
                    $data[] = $subjectId[0];

        }

        return $data;
    }


    public function validateRow($rows){
                Validator::make($rows->toArray(), [
                '*.full_name' => 'required|regex:/^[\pL\s\-]+$/u',
                '*.gender_mf' => 'required',
                '*.date_of_birth_yyyy_mm_dd' => 'required|date',
                '*.address' => 'required',
//                '*.address_area' => 'required',
                '*.birth_registrar_office_as_in_birth_certificate' => 'required',
                '*.nationality' => 'required',
                '*.identity_type' => 'required',
                '*.identity_number' =>  'required|unique:security_users,identity_number', //'required|unique:security_users,identity_type_id',
                '*.academic_period' => 'required',
                '*.education_grade' => 'required',
                '*.bmi_height' => 'required',
                '*.bmi_weight' => 'required',
                '*.bmi_date_yyyy_mm_dd' => 'required|date',
                '*.admission_no' => 'required',
                '*.start_date_yyyy_mm_dd' => 'required|date',
                '*.need_type' => 'required',
                '*.guardians_*' => 'required_without_all:*.fathers_*,*.mothers_*',
                '*.fathers_*' => 'required_without_all:*.guardians_*' ,
                '*.mothers_*' => 'required_without_all:*.guardians_*'

        ])->validate();

    }

}
