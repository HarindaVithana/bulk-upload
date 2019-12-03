<?php

namespace App\Console\Commands;

use App\Http\Controllers\StudentImportSuccessMailController;
use App\Imports\UsersImport;
use App\Imports\StudentUpdate;
use App\Mail\StudentImportFailure;
use App\Mail\StudentImportSuccess;
use App\Mail\EmptyFile;
use App\Mail\IncorrectTemplate;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Webpatser\Uuid\Uuid;

class ImportStudents extends Command
{
    use Importable;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:students {node}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk students data upload';

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
        $files = $this->getFiles();
        if(empty($files)){
            $files = $this->getTerminatedFiles();
        }
        while ($this->checkTime()){
            if($this->checkTime()){
                try {
                    if(!empty($files)){
                        $this->process($files);
                        unset($files);
                        exit();

                    }else{
                        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
                        $output->writeln('No files found,Waiting for files');
                        exit();

                    }

                }catch (Exception $e){
                    $output = new \Symfony\Component\Console\Output\ConsoleOutput();
                    $output->writeln($e);
                    sleep(300);
                    $this->handle();

                }
            }else{
                exit();
            }
        }
    }


    protected function  process($files){
        $time = Carbon::now()->tz('Asia/Colombo');
//        array_walk($files, array($this,'processSheet'));
        $node = $this->argument('node');
        $files[0]['node'] = $node;
        $this->processSheet($files[0]);
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $now = Carbon::now()->tz('Asia/Colombo');
        $output->writeln('=============== Time taken to batch ' .$now->diffInMinutes($time));

    }

    protected function getTerminatedFiles(){
        $files = Upload::where('is_processed', '=', 3)
            ->where('updated_at', '<=', Carbon::now()->tz('Asia/Colombo')->subHours(3))
            ->limit(1)
            ->get()->toArray();
        if(!empty($files)){
            DB::beginTransaction();
            DB::table('uploads')
                ->where('id', $files[0]['id'])
                ->update(['is_processed' => 3,'updated_at' => now()]);
            DB::commit();
        }
        return $files;
    }

    protected function getFiles(){
         $files = Upload::where('is_processed', '=', 0)
             ->limit(1)
            ->get()->toArray();
         if(!empty($files)){
             DB::beginTransaction();
             DB::table('uploads')
                 ->where('id', $files[0]['id'])
                 ->update(['is_processed' => 3,'updated_at' => now()]);
             DB::commit();
         }
         return $files;
    }

    protected function checkTime(){
        $time = Carbon::now()->tz('Asia/Colombo');
        $morning = Carbon::create($time->year, $time->month, $time->day, env('CRON_START_TIME',0), 29, 0)->tz('Asia/Colombo')->setHour(0); //set time to 05:59

        $evening = Carbon::create($time->year, $time->month, $time->day, env('CRON_END_TIME',0), 30, 0)->tz('Asia/Colombo')->setHour(23); //set time to 18:00

        $check = $time->between($morning,$evening, true);
        return true;
    }

    public function processSuccessEmail($file,$user,$subject) {
        $file['subject'] = $subject;
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $output->writeln('Processing the file: '.$file['filename']);
        try {
            Mail::to($user->email)->send(new StudentImportSuccess($file));
            DB::table('uploads')
                    ->where('id', $file['id'])
                    ->update(['is_processed' => 1, 'is_email_sent' => 1,'updated_at' => now()]);
        } catch (\Exception $ex) {
            DB::table('uploads')
                    ->where('id', $file['id'])
                    ->update(['is_processed' => 1, 'is_email_sent' => 2,'updated_at' => now()]);
        }
    }

    public function processFailedEmail($file,$user,$subject) {
        $file['subject'] = $subject;
        try {
            Mail::to($user->email)->send(new StudentImportFailure($file));
            DB::table('uploads')
                    ->where('id', $file['id'])
                    ->update(['is_processed' => 2, 'is_email_sent' => 1,'updated_at' => now()]);
        } catch (\Exception $ex) {
            DB::table('uploads')
                    ->where('id', $file['id'])
                    ->update(['is_processed' => 2, 'is_email_sent' => 2,'updated_at' => now()]);
        }
    }

    public function processEmptyEmail($file,$user,$subject) {
        $file['subject'] = $subject;
        try {
            Mail::to($user->email)->send(new EmptyFile($file));
            DB::table('uploads')
                ->where('id', $file['id'])
                ->update(['is_processed' => 2, 'is_email_sent' => 1,'updated_at' => now()]);
        } catch (\Exception $ex) {
            DB::table('uploads')
                ->where('id', $file['id'])
                ->update(['is_processed' => 2, 'is_email_sent' => 2,'updated_at' => now()]);
        }
    }


    protected function processSheet($file){
        $this->startTime = Carbon::now()->tz('Asia/Colombo');
        $user = User::find($file['security_user_id']);
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $output->writeln('##########################################################################################################################');
        $output->writeln('Processing the file: '.$file['filename']);
        if ($this->checkTime()) {
            try {
                $this->import($file,1,'C');
                $this->import($file,2,'B');

            } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                try {
                    Mail::to($user->email)->send(new IncorrectTemplate($file));
                    DB::table('uploads')
                            ->where('id', $file['id'])
                            ->update(['is_processed' => 2, 'is_email_sent' => 1,'updated_at' => now()]);
                } catch (\Exception $ex) {
                    $this->handle();
                    DB::table('uploads')
                            ->where('id', $file['id'])
                            ->update(['is_processed' => 2, 'is_email_sent' => 2 ,'updated_at' => now()]);
                }
            }
        } else {
            exit();
        }
    }

    protected function getType($file){
        $file =  storage_path() . '/app/sis-bulk-data-files/'.$file;
        $inputFileType =  \PhpOffice\PhpSpreadsheet\IOFactory::identify($file);
        return $inputFileType;
    }


    protected function getSheetWriter($file,$reader){
        switch ($this->getType($file['filename'])){
            case 'Xlsx':
                return new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($reader);
                break;
            case 'Ods':
                return new \PhpOffice\PhpSpreadsheet\Writer\Ods($reader);
                break;
            case 'Xml':
                return new \PhpOffice\PhpSpreadsheet\Writer\Xml($reader);
                break;
            default:
                return new \PhpOffice\PhpSpreadsheet\Writer\Xls($reader);
                break;
        }
    }

    protected function getSheetType($file){
        switch ($this->getType($file)){
            case 'Xlsx':
                return \Maatwebsite\Excel\Excel::XLSX;
                break;
            case 'Ods':
                return \Maatwebsite\Excel\Excel::ODS;
                break;
            case 'Xml':
                return \Maatwebsite\Excel\Excel::XML;
                break;
            default:
                return \Maatwebsite\Excel\Excel::XLS;
                break;
        }
    }


    protected function getSheetCount($file){
       $objPHPExcel = $this->setReader($file);
       return $objPHPExcel->getSheetCount();
    }



    /**
     * @param $file
     * @param $sheet
     * @param $column
     */
    protected function import($file, $sheet, $column){
            set_time_limit(300);
            $this->getFileSize($file);
             try {
                $user = User::find($file['security_user_id']);
                $excelFile = '/sis-bulk-data-files/' . $file['filename'];
                $this->higestRow = $this->getHigestRow($file, $sheet,$column);
                switch ($sheet){
                    case 1;
                        if (($this->getSheetName($file,'Insert Students')) && $this->higestRow > 0)  { //
                            $import = new UsersImport($file);
                            $import->import($excelFile,'local',$this->getSheetType($file['filename']));
//                            Excel::import($import, $excelFile, 'local');
                            DB::table('uploads')
                                ->where('id', $file['id'])
                                ->update(['insert' => 1,'is_processed' => 1,'updated_at' => now()]);
                            if($import->failures()->count() > 0){
                                self::writeErrors($import,$file,'Insert Students');
                                DB::table('uploads')
                                    ->where('id', $file['id'])
                                    ->update(['insert' => 3,'updated_at' => now()]);
                                $this->processFailedEmail($file,$user,'Fresh Student Data Upload:Partial Success ');
                                $this->stdOut('Insert Students',$this->higestRow);
                            }else{
                                $this->processSuccessEmail($file,$user,'Fresh Student Data Upload:Success ');
                                $this->stdOut('Insert Students',$this->higestRow);
                            }
                        }else if(($this->getSheetName($file,'Insert Students')) && $this->higestRow > 0) {
                            DB::table('uploads')
                                ->where('id', $file['id'])
                                ->update(['is_processed' => 2]);
                            $this->processEmptyEmail($file,$user, 'Fresh Student Data Upload ');
                        }
                        break;
                    case 2;
                        if (($this->getSheetName($file,'Update Students')) && $this->higestRow > 0) {
                            $import = new StudentUpdate($file);
                            $import->import($excelFile,'local',$this->getSheetType($file['filename']));
                            DB::table('uploads')
                                ->where('id', $file['id'])
                                ->update(['update' => 1,'is_processed' => 1,'updated_at' => now()]);
                            if($import->failures()->count() > 0){
                                self::writeErrors($import,$file,'Update Students');
                                DB::table('uploads')
                                    ->where('id', $file['id'])
                                    ->update(['update' => 3,'is_processed' => 1,'updated_at' => now()]);
                                $this->processFailedEmail($file,$user,'Existing Student Data Update:Partial Success ');
                                $this->stdOut('Update Students',$this->higestRow);
                            }else{
                                $this->processSuccessEmail($file,$user, 'Existing Student Data Update:Success ');
                                $this->stdOut('Update Students',$this->higestRow);
                            }
                        }else if(($this->getSheetName($file,'Update Students')) && $this->higestRow == 0) {
                            DB::table('uploads')
                                ->where('id', $file['id'])
                                ->update(['is_processed' => 2,'updated_at' => now()]);
                            $this->processEmptyEmail($file,$user, 'Existing Student Data Update');
                        }
                        break;
                }
            }catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                 if($sheet == 1){
                     self::writeErrors($e,$file,'Insert Students');
                     DB::table('uploads')
                         ->where('id', $file['id'])
                         ->update(['insert' => 2,'updated_at' => now()]);
                    $this->processFailedEmail($file,$user,'Fresh Student Data Upload:Failed');
                 }else if($sheet == 2){
                     self::writeErrors($e,$file,'Update Students');
                     DB::table('uploads')
                         ->where('id', $file['id'])
                         ->update(['update' => 2,'updated_at' => now()]);
                    $this->processFailedEmail($file,$user, 'Existing Student Data Update:Failed');
                 }
                 DB::table('uploads')
                     ->where('id',  $file['id'])
                     ->update(['is_processed' =>2 , 'updated_at' => now()]);

            }

    }

    protected function processErrors($failure){
            $error_mesg = implode(',',$failure->errors());
            $failure = [
                'row'=> $failure->row(),
                'errors' => [ $error_mesg],
                'attribute' => $failure->attribute()
            ];
            return $failure;

    }

    protected function  getFileSize($file){
        $excelFile =  '/sis-bulk-data-files/' . $file['filename'];
        $size = Storage::disk('local')->size($excelFile);
        $user = User::find($file['security_user_id']);
        if( $size > 0){
            return true;
        }else{
            DB::table('uploads')
                ->where('id',  $file['id'])
                ->update(['is_processed' =>2 , 'updated_at' => now()]);
            $this->stdOut('No valid data found :Please re-upload the file',0);
            $this->processEmptyEmail($file,$user, 'No valid data found :Please re-upload the file');
        }
    }

    protected function setReader($file){
        $excelFile =  '/sis-bulk-data-files/processed/' . $file['filename'];
        $exists = Storage::disk('local')->exists($excelFile);
        if(!$exists){

            $excelFile =  "/sis-bulk-data-files/" . $file['filename'];
        }
        $excelFile = storage_path()."/app" . $excelFile;
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($this->getType($file['filename']));
        $objPHPExcel =  $reader->load($excelFile);
        return $objPHPExcel;
    }

    protected function  getSheetName($file,$sheet){
        $objPHPExcel = $this->setReader($file);
        return $objPHPExcel->getSheetByName($sheet)  !== null;
    }

    protected function getHigestRow($file,$sheet,$column){
        try{
            $reader = $this->setReader($file);
            $reader->setActiveSheetIndex($sheet);
            $higestRow = 0;
            $highestRow =  $reader->getActiveSheet()->getHighestRow($column);
            for ($row = 3; $row <= $highestRow; $row++) {
                $rowData = $reader->getActiveSheet()->getCell($column.$row)->getValue();
                if (empty($rowData) || $rowData == null) {
                    continue;
                } else {
                    $higestRow += 1;
                }
            }
            return $higestRow;
        }catch(\Exception $e){
            $user = User::find($file['security_user_id']);
            DB::beginTransaction();
            DB::table('uploads')
                ->where('id', $file['id'])
                ->update(['is_processed' => 2,'updated_at' => now()]);
            DB::commit();
            $this->processEmptyEmail($file,$user, 'No valid data found');
            exit();
        }
    }

    protected function stdOut($title,$rows){
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $output->writeln(   $title. ' Process completed at . '.' '. now());
        $now = Carbon::now()->tz('Asia/Colombo');
        $output->writeln('Total Processed lines: ' . $rows);
        $output->writeln( 'Time taken to process           : '.   $now->diffInSeconds($this->startTime) .' Seconds');
        $output->writeln('--------------------------------------------------------------------------------------------------------------------------');
    }



    protected function removeRows($row,$count,$params){
        $reader = $params['reader'];
        $sheet = $reader->getActiveSheet();
        if(!in_array($row,$params['rows'])){
            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $output->writeln(    ' removing row . '.' '. $row);
            $reader->getActiveSheet()->getCellCollection()->removeRow($row);
        }
    }


    protected function writeErrors($e,$file,$sheet){
        try {
            ini_set('memory_limit', '2048M');
            $baseMemory = memory_get_usage();
            gc_enable();
            gc_collect_cycles();
            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $cacheMethod = \PHPExcel_CachedObjectStorageFactory:: cache_to_phpTemp;
            $cacheSettings = array( ' memoryCacheSize ' => '256MB');
            \PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
            ini_set('memory_limit', -1);
            $failures = $e->failures();
            $reader = $this->setReader($file);
            $reader->setActiveSheetIndexByName($sheet);

            $failures = gettype($failures) == 'object' ? array_map(array($this,'processErrors'),iterator_to_array($failures)) : array_map(array($this,'processErrors'),($failures));
            if(count($failures) > 0){
                $rows = array_map('rows',$failures);
                $rows = array_unique($rows);
                $rowIndex =   range(3,$this->higestRow+2);
                $params = [
                    'rows' =>$rows,
                    'reader' => $reader
                ];
                array_walk($failures , 'append_errors_to_excel',$reader);
                array_walk($rowIndex , array($this,'removeRows'),$params);
                $objWriter = $this->getSheetWriter($file,$reader);
                Storage::disk('local')->makeDirectory('sis-bulk-data-files/processed');
                $objWriter->save(storage_path() . '/app/sis-bulk-data-files/processed/' . $file['filename']);
                $now = Carbon::now()->tz('Asia/Colombo');
                $output->writeln(  $reader->getActiveSheet()->getTitle() . ' Process completed at . '.' '. now());
                $output->writeln('memory usage for the processes : '.(memory_get_usage() - $baseMemory));
                $output->writeln( 'Time taken to process           : '.   $now->diffInSeconds($this->startTime) .' Seconds');
                $output->writeln(' errors reported               : '.count($failures));
                $output->writeln('--------------------------------------------------------------------------------------------------------------------------');
                unset($objWriter);
                unset($failures);
            }

        }catch (Eception $e){
            $user = User::find($file['security_user_id']);
            DB::beginTransaction();
            DB::table('uploads')
                ->where('id', $file['id'])
                ->update(['is_processed' => 2,'updated_at' => now()]);
            DB::commit();
            $this->processEmptyEmail($file,$user, 'No valid data found');
            exit();
        }
    }
}
