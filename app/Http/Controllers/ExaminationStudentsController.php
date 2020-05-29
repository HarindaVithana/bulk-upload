<?php

namespace App\Http\Controllers;

use Session;
use App\Models\Institution;
use Illuminate\Http\Request;
use App\Models\Security_user;
use Lsflk\UniqueUid\UniqueUid;
use App\Models\Academic_period;
use App\Models\Education_grade;
use App\Models\Institution_class;
use App\Models\Examination_student;
use App\Models\Institution_student;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use App\Models\Institution_class_student;
use App\Exports\ExaminationStudentsExport;
use App\Imports\ExaminationStudentsImport;
use App\Models\Institution_student_admission;

class ExaminationStudentsController extends Controller
{
    public function __construct($year = 2019, $grade = 'G5')
    {
        $this->year = $year;
        $this->grade = $grade;
        $this->student = new Security_user();
        $this->examination_student = new Examination_student();
        $this->academic_period =  Academic_period::where('code', '=', $this->year)->first();
        $this->education_grade = Education_grade::where('code', '=', $this->grade)->first();
        $this->uniqueId = new UniqueUid();
        $this->output = new \Symfony\Component\Console\Output\ConsoleOutput();
    }

    public function index()
    {
        return view('uploadcsv');
    }

    public function uploadFile(Request $request)
    {

        if ($request->input('submit') != null) {

            $file = $request->file('file');

            // File Details
            $filename = 'exams_students.csv';
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();

            // Valid File Extensions
            $valid_extension = array("csv");

            // 20MB in Bytes
            $maxFileSize = 20971520;

            // Check file extension
            if (in_array(strtolower($extension), $valid_extension)) {

                // Check file size
                if ($fileSize <= $maxFileSize) {

                    // File upload location
                    Storage::disk('local')->putFileAs(
                        'examination/',
                        $file,
                        $filename
                    );
                    Session::flash('message', 'File upload successfully!');
                    // Redirect to index
                } else {
                    Session::flash('message', 'File too large. File must be less than 20MB.');
                }
            } else {
                Session::flash('message', 'Invalid File Extension.');
            }
        }
        return redirect()->action('ExaminationStudentsController@index');
    }

    /**
     * Import students data to the Examinations table 
     *
     * @return void
     */
    public static function callOnClick()
    {
        // Import CSV to Database
        $excelFile = "/examination/exams_students.csv";

        $import = new ExaminationStudentsImport();
        $import->import($excelFile, 'local', \Maatwebsite\Excel\Excel::CSV);
    }

    /**
     * Iterate over existing student's data
     *
     * @return void
     */
    public  function doMatch()
    {
        $students = Examination_student::get()->toArray();
        //    array_walk($students,array($this,'clone'));
        array_walk($students, array($this, 'clone'));
    }

    /**
     * Undocumented function
     *
     * @param [type] $student
     * @return void
     */
    public function clone($student)
    {
        //get student matching with same dob and gender
        $matchedStudent = $this->getMatchingStudents($student);

        // if the first match missing do complete insertion
        $institution = Institution::where('code', '=', $student['schoolid'])->first();

        if (!is_null($institution)) {
            // ge the class lists to belong the school
            $institutionClass = Institution_class::where(
                [
                    'institution_id' => $institution->id,
                    'academic_period_id' => $this->academic_period->id,
                    'education_grade_id' => $this->education_grade->id
                ]
            )->join('institution_class_grades', 'institution_classes.id', 'institution_class_grades.institution_class_id')->get()->toArray();

            // set search variables 
            $admissionInfo = [
                'instituion_class' => $institutionClass,
                'instituion' => $institution,
                'education_grade' =>  $this->education_grade,
                'academic_period' => $this->academic_period
            ];
            // if no matching found
            if (empty($matchedStudent)) {
                $sis_student = $this->student->insertExaminationStudent($student);
                $this->updateStudentId($student, $sis_student);

                //TODO implement insert student to admission table
                $student['id'] = $sis_student['id'];
                if (count($institutionClass) == 1) {
                    $admissionInfo['instituion_class'] = $institutionClass[0];
                    Institution_student::createExaminationData($student, $admissionInfo);
                    Institution_student_admission::createExaminationData($student, $admissionInfo);
                    Institution_class_student::createExaminationData($student, $admissionInfo);
                } else {
                    Institution_student_admission::createExaminationData($student, $admissionInfo);
                    Institution_student::createExaminationData($student, $admissionInfo);
                }
                // update the matched student's data    
            } else {
                $this->student->updateExaminationStudent($student, $matchedStudent);
                $matchedStudent = array_merge((array) $student, $matchedStudent);
                Institution_student::updateExaminationData($matchedStudent, $admissionInfo);
                $matchedStudent['id'] = $matchedStudent['student_id'];
                $this->updateStudentId($student, $matchedStudent);
            }
        }
    }

    /**
     * This function is implemented fuzzy search algorithm 
     * to get the most matching name with the existing students
     * data set
     *
     * @param [type] $student
     * @return array
     */
    public function getMatchingStudents($student)
    {
        $sis_users = $this->student->getMatches($student);
        $studentData = [];
        if (!is_null($sis_users) && (count($sis_users) > 0)) {
            $studentData = $this->searchSimilarName($student, $sis_users);
        }
        return $studentData;
    }

    /**
     * Search most matching name
     *
     * @param [type] $student
     * @param [type] $sis_students
     * @return void
     */
    public function searchSimilarName($student, $sis_students)
    {
        $highest = [];
        $previousValue = null;
        foreach ($sis_students as $key => $value) {
            similar_text(get_l_name($student['f_name']), get_l_name($value['first_name']), $percentage);
            $value['rate'] = $percentage;
            if (($previousValue)) {
                $highest =  ($percentage > $previousValue['rate']) ? $value : $value;
            } else {
                $highest = $value;
            }
            $previousValue = $value;
        }

        //If the not matched 100% try to get most highest value with full name
        if(!($highest['rate'] > 99) ){
            foreach ($sis_students as $key => $value) {
                similar_text($student['f_name'],$value['first_name'], $percentage);
                $value['rate'] = $percentage;
                if (($previousValue)) {
                    $highest =  ($percentage > $previousValue['rate']) ? $value : $value;
                } else {
                    $highest = $value;
                }
                $previousValue = $value;
            }
        }
        return $highest;
    }

    /**
     * Generate new NSID for students
     *
     * @param [type] $student
     * @param [type] $sis_student
     * @return void
     */
    public function updateStudentId($student, $sis_student)
    {
        $student['nsid'] =  $sis_student['openemis_no'];

        $this->student->where('id', $sis_student['id'])->update([
            'openemis_no' => $sis_student['openemis_no']
        ]);
        // add new NSID to the examinations data set
        $this->examination_student->where(['st_no' => $student['st_no']])->update($student);
        $this->output->writeln('Updated '.$sis_student['id'] .' to NSID'. $sis_student['openemis_no']);
    }

    /**
     * export the all data with NSID
     *
     * @return void
     */
    public function export()
    {
        return Excel::download(new ExaminationStudentsExport, 'Students_data_with_nsid.csv');
    }
}
