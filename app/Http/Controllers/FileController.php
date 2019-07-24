<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{



    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request){
        $uploadFile = $request->file('import_file');
        $fileName = time().'_'.$uploadFile->getClientOriginalName();
        Storage::disk('local')->putFileAs(
            'sis-bulk-data-files/',
            $uploadFile,
            $fileName
        );

        $upload = new Upload;
        $upload->fileName =$fileName;
        $upload->model = 'Student';
        $upload->institution_class_id = $request->input('class');
        $upload->user()->associate(auth()->user());
        $upload->save();

        return redirect('/')->withSuccess('The file is uploaded, we will process and let you know by your email');
    }


    public function downloadTemplate(){
        $file= storage_path().'/app/public/censusNo_className_sis_students_bulk_upload.xlsx';
        return Response::download($file);
    }
}
