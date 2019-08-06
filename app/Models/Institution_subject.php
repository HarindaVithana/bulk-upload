<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution_subject extends Model  {

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'institution_subjects';

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'no_of_seats', 'total_male_students', 'total_female_students', 'institution_id', 'education_grade_id', 'education_subject_id', 'academic_period_id', 'modified_user_id', 'modified', 'created_user_id', 'created'];

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

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['modified', 'created', 'modified', 'created'];


    public  function institutionGradeSubject(){
        return $this->belongsTo('App\Models\Education_grades_subject','education_grade_id','education_grade_id');
    }

    public  function institutionOptionalGradeSubject(){
        return $this->belongsTo('App\Models\Education_grades_subject','education_grade_id','education_grade_id')
            ->where('education_grades_subjects.auto_allocation','=',0);;
    }

    public  function institutionMandatoryGradeSubject(){
        return $this->belongsTo('App\Models\Education_grades_subject','education_grade_id','education_grade_id')
            ->where('education_grades_subjects.auto_allocation','=',1);
    }


    public  function institutionClassSubject(){
        return $this->hasMany('App\Models\Institution_class_subject','institution_class_id','id');
    }





}