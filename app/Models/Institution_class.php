<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution_class extends Base_Model
{

    public const CREATED_AT = 'created';
    public const UPDATED_AT = 'modified';



    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'institution_classes';

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'no_of_students', 'class_number', 'total_male_students', 'total_female_students', 'staff_id', 'secondary_staff_id', 'institution_shift_id', 'institution_id', 'academic_period_id', 'modified_user_id', 'modified', 'created_user_id', 'created'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [];

    //    protected

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['modified', 'created'];

    public function class_teacher()
    {
        return $this->belongsTo('App\Models\Security_group_user', 'staff_id', 'security_user_id');
    }

    public function institution()
    {
        return $this->belongsTo('App\Models\Institution', 'institution_id');
    }


    public function getShiftClasses($shift, $al)
    {
        $query = self::query()
            ->select(
                'institution_classes.id',
                'institution_classes.institution_id',
                'institution_classes.institution_shift_id',
                'institution_classes.name',
                'institution_classes.no_of_students',
                'institution_classes.class_number',
                'institution_class_grades.education_grade_id',
                'education_programmes.education_cycle_id'

            )
            ->join('institution_class_grades', 'institution_classes.id', 'institution_class_grades.institution_class_id')
            ->join('education_grades','institution_class_grades.education_grade_id','education_grades.id')  
            ->join('education_programmes', 'education_grades.education_programme_id', 'education_programmes.id')
            ->join('education_cycles', 'education_programmes.education_cycle_id','education_cycles.id')
            ->groupBy('institution_classes.id');

        if ($al == true) {
            $query->where('education_programmes.education_cycle_id', 3)
            ->where('institution_id', $shift);
            $data = $query
            ->groupBy('institution_classes.id')
            ->get()->toArray();
            return $data;
        } else {
            $query->where('education_programmes.education_cycle_id', '<', 2)
            ->where('institution_shift_id', $shift);
            $data = $query
            ->get()->toArray();
            return $data;
        }
    }
}
