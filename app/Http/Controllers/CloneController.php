<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Academic_period;
use App\Models\Institution_class;
use App\Models\Institution_shift;
use Illuminate\Support\Facades\DB;
use App\Models\Institution_subject;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Institution_class_grade;
use App\Models\Education_grades_subject;
use App\Models\Institution_class_subject;

class CloneController extends Controller
{
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()

    {
        $this->shifts = new Institution_shift();
        $this->academic_period = new Academic_period();
        $this->institution_classes = new Institution_class();
        $this->institution_class_subjects = new Institution_class_subject();
        $this->institution_subjects =  new Institution_subject();
        $this->education_grade_subjects =  new Education_grades_subject();
        $this->output = new \Symfony\Component\Console\Output\ConsoleOutput();
    }


    public function array_walk($shift, $count, $params)
    {
        array_walk($shift, array($this, 'process'), $params);
    }

    public function cleanConfig($params)
    {
        $academicPeriod = $params['academic_period'];
        $this->shifts->where(['academic_period_id' => $academicPeriod->id])->delete();
        $this->output->writeln('cleaned shifts');

        $this->shifts->where(['cloned' => $academicPeriod->code])->update(['cloned' => '2019']);
        $this->output->writeln('updated shifts');

        $classIds =  $this->institution_classes->select('id')->where(['academic_period_id' => $academicPeriod->id])->get()->toArray();
        $this->institution_classes->where(['academic_period_id' => $academicPeriod->id])->delete();
        $this->output->writeln('cleaned classes');

        $this->institution_class_subjects->whereNotIn('institution_class_id', $classIds)->delete();
        $this->output->writeln('cleaned subjects');

        do {
            $deleted =  $this->institution_subjects->where('academic_period_id', $academicPeriod->id)->limit(100000)->delete();
            $this->output->writeln('100000 institutions cleaned shifts');
        } while ($deleted > 0);
    }

    public function process($shift, $count, $params)
    {
        // echo ('[' . getmypid() . ']This Process executed at' . date("F d, Y h:i:s A") . "\n");
        $year = $params['year'];
        $academicPeriod = $params['academic_period'];
        $previousAcademicPeriod = $params['previous_academic_period'];
        $mode = $params['mode'] == 'AL' ? true : false;

        $shiftId = $this->updateShifts($year, $shift);

        $params = [
            'previous_academic_period_id' => $previousAcademicPeriod->id,
            'academic_period_id' => $academicPeriod->id,
            'shift_id' => $shiftId
        ];


        if ($mode) {
            $institutionClasses = $this->institution_classes->getShiftClasses($shift['institution_id'],  $mode);
            $institutionSubjects = $this->institution_subjects->getInstitutionSubjects($shift['institution_id'], $previousAcademicPeriod->id,  $mode);
            array_walk($institutionClasses, array($this, 'updateInstitutionClasses'), $params);
        } else {
            $institutionClasses = $this->institution_classes->getShiftClasses($shift['institution_id'], $mode);
            $institutionSubjects = $this->institution_subjects->getInstitutionSubjects($shift['institution_id'], $previousAcademicPeriod->id, $mode);
            try {
                array_walk($institutionSubjects, array($this, 'insertInstitutionSubjects'), $academicPeriod);
                if (!empty($institutionClasses) && !is_null($shiftId) && !is_null($academicPeriod)) {

                    $newInstitutionClasses = $this->generateNewClass($institutionClasses, $shiftId, $academicPeriod->id);

                    

                    try {
                        $newInstitutionClasses = $this->institution_classes->getShiftClasses($shiftId, $mode);
                        if (!$mode) {
                            array_walk($newInstitutionClasses, array($this, 'insertInstitutionClasses'), $params);
                        } else {
                        }
                        $this->output->writeln('##########################################################################################################################');
                        $this->output->writeln('updating from ' . $shiftId);
                    } catch (\Exception $e) {
                        dd($e);
                        Log::error($e->getMessage(), [$e]);
                    }
                }
                //            DB::commit();
            } catch (\Exception $e) {
                //            DB::rollBack();
                Log::error($e->getMessage(), [$e]);
            }
        }
    }


    /**
     * @param $subjects
     * @param $count
     * @param $academicPeriod
     */
    public function insertInstitutionSubjects($subjects, $count, $academicPeriod)
    {
        try {
            $subjects['academic_period_id'] = $academicPeriod->id;
            $subjects['created'] = now();
            unset($subjects['total_male_students']);
            unset($subjects['total_female_students']);
            unset($subjects['id']);
            $this->institution_subjects->create($subjects);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), [$e]);
        }
    }

    public function updateInstitutionClasses($class, $count, $params)
    {
        Institution_class::where('id', $class['id'])
            ->update([
                'institution_shift_id' => $params['shift_id'],
                'academic_period_id' => $params['academic_period_id']
            ]);
    }

    public function  insertInstitutionClasses($class, $count, $param)
    {
        try {

            $academicPeriod = $param['academic_period_id'];
            $educationGrdae = $class['education_grade_id'];

            $classId = $class['id'];
            unset($class['id']);
            $institutionSubjects = Institution_subject::query()->where('education_grade_id', $class['education_grade_id'])
                ->where('institution_id', $class['institution_id'])
                ->where('academic_period_id', $academicPeriod)->get()->toArray();
            $params = [
                'class' => $class,
                'subjects' => $institutionSubjects,
                'academic_period_id' => $academicPeriod,
                'classId' => $classId
            ];
            unset($class['education_grade_id']);
            $noOfStudents = $class['no_of_students'] == 0 ? 40 : $class['no_of_students'];
            $class['academic_period_id'] = $academicPeriod;
            $class['no_of_students'] = $noOfStudents;
            $class['created'] = now();
            $class['institution_shift_id'] = $param['shift_id'];
            $this->output->writeln('Create class:' . $class['name']);
            $class = Institution_class::create($class);
            $institutionClassGrdaeObj['institution_class_id'] = $class->id;
            $institutionClassGrdaeObj['education_grade_id'] = $educationGrdae;
            Institution_class_grade::create($institutionClassGrdaeObj);
            $institutionSubjects = Institution_subject::query()->where('education_grade_id', $educationGrdae)
                ->where('institution_id', $class->institution_id)
                ->where('academic_period_id', $academicPeriod)
                ->groupBy('education_subject_id')
                ->get()
                ->toArray();
            $params['class'] = $class;
            $this->insertInstitutionClassSubjects($institutionSubjects, $class);
            //                array_walk($classSubjects,array($this,'insertInstitutionClassSubjects'),$params);
        } catch (\Exception $e) {
            Log::error($e->getMessage(), [$e]);
        }
    }

    public function insertInstitutionClassSubjects($subjects, $class)
    {
        if (!empty($subjects)) {
            try {
                array_walk($subjects, array($this, 'insertClassSubjects'), $class);
                $this->output->writeln('updating subjects ' . $class->name);
            } catch (\Exception $e) {
                Log::error($e->getMessage(), [$e]);
            }
        };
    }


    public function insertClassSubjects($subject, $count, $newClassId)
    {
        try {
            $subjectobj['status'] = 1;
            $subjectobj['created_user_id'] = 1;
            $subjectobj['created'] = now();

            $subjectobj['institution_class_id'] = $newClassId->id;
            $subjectobj['institution_subject_id'] = $subject['id'];

            if (!$this->institution_class_subjects->isDuplicated($subjectobj)) {
                $this->institution_class_subjects->create($subjectobj);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage(), [$e]);
        }
    }

    /**
     * generate new class object for new academic year
     *
     * @param $classes
     * @param $shiftId
     * @param $academicPeriod
     * @return array
     */
    public function generateNewClass($classes, $shiftId, $academicPeriod)
    {
        $newClasses = [];
        foreach ($classes as $class) {
            $noOfStudents = $class['no_of_students'] == 0 ? 40 : $class['no_of_students'];
            $class['academic_period_id'] = $academicPeriod;
            $class['no_of_students'] = $noOfStudents;
            $class['created'] = now();
            $class['institution_shift_id'] = $shiftId;
            array_push($newClasses, $class);
        }
        return $newClasses;
    }

    /**
     * update shifts
     * @param $year
     * @param $shift
     * @return mixed
     */
    public function updateShifts($year, $shift)
    {
        $academicPeriod = $this->academic_period->getAcademicPeriod($year);
        $this->shifts->where('id', $shift['id'])->update(['cloned' => $year]);
        $shift['academic_period_id'] = $academicPeriod->id;
        $exist = $this->shifts->shiftExists($shift);
        return $this->shifts->create((array)$shift)->id;
    }
}
