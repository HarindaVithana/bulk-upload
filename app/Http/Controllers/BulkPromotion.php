<?php

namespace App\Http\Controllers;

use Webpatser\Uuid\Uuid;
use App\Models\Institution;
use App\Models\Academic_period;
use App\Models\Education_grade;
use App\Models\Institution_class;
use App\Models\Institution_student;
use App\Models\Institution_subject;
use Illuminate\Support\Facades\Log;
use App\Models\Institution_class_student;
use App\Models\Institution_class_subject;
use App\Models\Institution_subject_student;
use App\Models\Institution_student_admission;

class BulkPromotion extends Controller
{
    public function __construct()
    {
        $this->instituion_grade = new \App\Models\Institution_grade();
        $this->education_grades = new Education_grade();
        $this->academic_period = new Academic_period();
        $this->institution_students = new Institution_student();
        $this->institutions = new Institution();
        $this->institution_class_students = new Institution_class_student();
        $this->institution_classes = new Institution_class();
        $this->institution_student_admission = new Institution_student_admission();
    }

    /**
     * Process Grade wise
     *
     * @param [type] $institutionGrade
     * @param [type] $year
     * @return void
     */
    public function callback($institutionGrade, $params)
    {
        array_walk($institutionGrade, array($this, 'processGrades'), $params);
    }

    /**
     * Process Cloning process based on institution grade
     *
     * @param [type] $institutionGrade
     * @param [type] $count
     * @param [type] $year
     * @return void
     */
    public function processGrades($institutionGrade, $count, $params)
    {
        if (!empty($institutionGrade) && $this->institutions->isActive($institutionGrade['institution_id'])) {
            $this->instituion_grade->updatePromoted($params['academicPeriod']->code, $institutionGrade['id']);
            $isAvailableforPromotion = false;
            $nextGrade = $this->education_grades->getNextGrade($institutionGrade['education_grade_id']);

            if (!empty($nextGrade)) {
                $isAvailableforPromotion = $this->instituion_grade->getInstitutionGrade($institutionGrade['institution_id'], $nextGrade->id);
            }

            if (!empty($isAvailableforPromotion)) {
                $this->process($institutionGrade, $nextGrade, $params);
            }
            //leave school levers
            // else {
            //     $this->process($institutionGrade, $nextGrade, $params);
            // }
        }
    }


    /**
     * Promote students of grate to next grade
     *
     * @param $institutionGrade
     * @param $nextGrade
     * @param $academicPeriod
     * @param $nextAcademicPeriod
     * @param array $parallelClasses
     * @param $status
     */
    public function promotion($institutionGrade, $nextGrade, $academicPeriod, $nextAcademicPeriod, $parallelClasses = [], $status)
    {
        $institution = Institution::where('id', $institutionGrade['institution_id'])->get()->first();
        $studentListToPromote = $this->institution_students->getStudentListToPromote($institutionGrade, $academicPeriod);

        $params = [
            $nextAcademicPeriod,
            $institutionGrade,
            $nextGrade,
            $status
        ];

        try {
            array_walk($studentListToPromote, array($this, 'promote'), $params);

            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $output->writeln('##########################################################################################################################');
            $output->writeln('Promoting from ' . $institutionGrade['name'] . ' IN ' . $institution->name . ' No of Students: ' . count($studentListToPromote));


            if (!empty($parallelClasses)) {
                $params = [
                    $nextAcademicPeriod,
                    $institutionGrade,
                    $nextGrade,
                    $parallelClasses,
                    $status
                ];
                array_walk($studentListToPromote, array($this, 'assingeToClasses'), $params);
                array_walk($parallelClasses, array($this, 'updateStudentCount'));
            }
        } catch (\Exception $e) {
            dd($e);
            Log::error($e->getMessage());
        }
    }


    /**
     * update students count on class rooms
     *
     * @param [type] $class
     * @return void
     */
    public function updateStudentCount($class)
    {
        $studentCounts = Institution_class_student::getStudentsCount($class['id']);
        unset($studentCounts['total']);
        Institution_class::query()->where('id', $class['id'])->update($studentCounts);
    }


    /**
     * Process institution grade in to the define promotion senarios
     *
     * @param $institutionGrade
     * @param $nextGrade
     * @param $year
     * @return int
     */
    public function process($institutionGrade, $nextGrade, $params)
    {
        $academicPeriod = $params['academicPeriod'];
        $previousAcademicPeriod = $params['previousAcademicPeriod'];
        $nextGradeObj = null;
        if ($nextGrade !== []  && !is_null($nextGrade)) {
            $currentGradeObj = $this->instituion_grade->getParallelClasses($institutionGrade['id'], $institutionGrade['institution_id'], $institutionGrade['education_grade_id'], $previousAcademicPeriod->id);
            $nextGradeObj = $this->instituion_grade->getParallelClasses($institutionGrade['id'], $institutionGrade['institution_id'], $nextGrade->id, $academicPeriod->id);
        }

        if (!is_null($nextGradeObj)) {
            if ($nextGradeObj->count() == 1) {
                // promote parallel classes
                $this->promotion($institutionGrade, $nextGrade, $previousAcademicPeriod, $academicPeriod, $nextGradeObj->toArray(), 1);
                return 1;
            } elseif (($nextGradeObj->count() > 1) && ($nextGradeObj->count() !==  $currentGradeObj->count())) {
                // promote pool promotion
                $this->promotion($institutionGrade, $nextGrade, $previousAcademicPeriod, $academicPeriod, [], 1);
                return 2;
            } elseif (($nextGradeObj->count() > 1) && $currentGradeObj->count() == $nextGradeObj->count()) {
                // Promote matching class name with previous class
                $this->promotion($institutionGrade, $nextGrade, $previousAcademicPeriod, $academicPeriod, $nextGradeObj->toArray(), 1);
                return 1;
            } else {
                // default pool promotion
                $this->promotion($institutionGrade, $nextGrade, $previousAcademicPeriod, $academicPeriod, [], 1);
                return 2;
            }
        } else {
            // default pool promotion
            $this->promotion($institutionGrade, $nextGrade, $previousAcademicPeriod, $academicPeriod, [], 3);
            return 2;
        }
    }


    /**
     * update promoted student's data in to the DB
     *
     * @param $student
     * @param $count
     * @param $params
     */
    public function promote($student, $count, $params)
    {

        $academicPeriod = $params[0];
        $nextGrade = $params[2];
        $status = $params[3];
        $studentData = [
            'student_status_id' => $status,
            'education_grade_id' => $nextGrade !== null ? $nextGrade->id : $student['education_grade_id'],
            'academic_period_id' => $academicPeriod->id,
            'start_date' => $academicPeriod->start_date,
            'start_year' => $academicPeriod->start_year,
            'end_date' => $academicPeriod->end_date,
            'end_year' =>   $academicPeriod->end_year,
            'institution_id' => $student['institution_id'],
            'admission_id' => $student['admission_id'],
            // 'student_id' => $student['id'],
            'created_user_id' => $student['created_user_id']
        ];

        try {
            Institution_student::where('id', (string)$student['id'])->update($studentData);
            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $output->writeln('----------------- ' . $student['admission_id'] . ' to ' . $studentData['education_grade_id']);
        } catch (\Exception $e) {
            dd($e);
            Log::error($e->getMessage());
        }
    }


    /**
     * get promoted new class of the students
     *
     * @param $student
     * @param $educationGrade
     * @param $nextGrade
     * @param $classes
     * @return false|int|string|null
     */
    public function getStudentClass($student, $educationGrade, $nextGrade, $classes)
    {
        $studentClass = $this->institution_class_students->getStudentNewClass($student);
        if (!is_null($studentClass)) {
            return  array_search(str_replace($educationGrade['name'], $nextGrade->name, $studentClass->name), array_column($classes, 'name'));
        } else {
            return false;
        }
    }

    /**
     * Create class entry for promoted students
     *
     * @param $student
     * @param $count
     * @param $params
     */
    public function assingeToClasses($student, $count, $params)
    {
        $academicPeriod = $params[0];
        $educationGrade = $params[1];
        $nextGrade = $params[2];
        $classes = $params[3];
        $status = $params[4];
        $class = null;
        if (count($classes) == 1) {
            $class = $classes[0];
        } else {
            $class = $this->getStudentClass($student, $educationGrade, $nextGrade, $classes);
            $class = $classes[$classes];
        }

        if (!is_null($class)) {

            $studentObj = [
                'student_id' => $student['student_id'],
                'institution_class_id' =>  $class['id'],
                'education_grade_id' =>  $nextGrade->id,
                'academic_period_id' => $academicPeriod->id,
                'institution_id' => $student['institution_id'],
                'student_status_id' => $status,
                'created_user_id' => $student['created_user_id']
            ];
            $allSubjects = Institution_class_subject::getAllSubjects($class['id']);

            if (!empty($allSubjects)) {
                $allSubjects = unique_multidim_array($allSubjects, 'institution_subject_id');
                $this->student = $studentObj;
                $allSubjects = array_map(array($this, 'setStudentSubjects'), $allSubjects);
                $allSubjects = unique_multidim_array($allSubjects, 'education_subject_id');
                array_walk($allSubjects, array($this, 'insertSubject'));
            }
            if (!$this->institution_class_students->isDuplicated($studentObj)) {
                $this->institution_class_students->create($studentObj);
                $output = new \Symfony\Component\Console\Output\ConsoleOutput();
                $output->writeln('----------------- ' . $student['student_id'] . 'to ' . $class['name']);
            } else {
                $this->institution_class_students->where('id', (string)$student['id'])->update($studentObj);
                $output = new \Symfony\Component\Console\Output\ConsoleOutput();
                $output->writeln('----------------- ' . $student['student_id'] . 'to ' . $class['name']);
            }
        }
    }

    /**
     * Update subject count
     *
     * @param [type] $subject
     * @return void
     */
    protected function updateSubjectCount($subject)
    {
        $totalStudents = Institution_subject_student::getStudentsCount($subject['institution_subject_id']);
        Institution_subject::where(['institution_subject_id' => $subject->institution_subject_id])
            ->update([
                'total_male_students' => $totalStudents['total_male_students'],
                'total_female_students' => $totalStudents['total_female_students']
            ]);
    }


    /**
     * Set student subjects
     *
     * @param [type] $subject
     * @return void
     */
    protected function setStudentSubjects($subject)
    {
        return [
            'id' => (string) Uuid::generate(4),
            'student_id' => $this->student['student_id'],
            'institution_class_id' => $this->student['institution_class_id'],
            'institution_subject_id' => $subject['institution_subject_id'],
            'institution_id' => $this->student['institution_id'],
            'academic_period_id' => $this->student['academic_period_id'],
            'education_subject_id' => $subject['institution_subject']['education_subject_id'],
            'education_grade_id' => $this->student['education_grade_id'],
            'student_status_id' => 1,
            'created_user_id' => $this->student['created_user_id'],
            'created' => now()
        ];
    }

    /**
     * Insert subjects
     *
     * @param [type] $subject
     * @return void
     */
    protected function insertSubject($subject)
    {
        if (!Institution_subject_student::isDuplicated($subject)) {
            Institution_subject_student::updateOrInsert($subject);
        }
    }
}
