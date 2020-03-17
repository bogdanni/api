<?php

require_once 'interface.Module.php';
/**
 * WebGLClient Module
 * 
 * Used for getting data on a set of planets and satellites as seen from a set of observers.
 * Retrieves data stored in JSON file format on the disk based on request time as a unix timestamp.
 */

require_once HV_ROOT_DIR.'/../src/Database/ImgIndex.php';
require_once HV_ROOT_DIR.'/../src/Image/JPEG2000/JP2Image.php';

class Module_WebGLClient implements Module {

    private $_params;
    private $_options;

    /**
     * WebGL Client constructor
     * 
     * @param mixed &$params API request parameters
     */
    public function __construct(&$params) {
        $this->_params = $params;
        $this->_options = array();
        $this->db = new Database_ImgIndex();
        $this->imageSubRegion = array(
            'top'    => 0,
            'left'   => 0,
            'bottom' => 4096,
            'right'  => 4096
        );
        //sets bmp extraction resolution
        if(isset($this->_params["reduce"])){
            $inputReduce = $this->_params["reduce"];
            if($inputReduce == "0"){
                $this->reduce = 0;
            }else if($inputReduce == "1"){
                $this->reduce = 1;
            }else if($inputReduce == "2"){
                $this->reduce = 2;
            }else if($inputReduce == "3"){
                $this->reduce = 3;
            }else{
                $this->reduce = 2;// fall back on 1/4 res
            }
        }else{
            $this->reduce = 2; //0 = none, 1 = 1/2, 2 = 1/4 ... etc
        }
        //sets final jpg cache scale
        if($this->reduce > 0){
            $this->resolution = 4096 / pow(2,$this->reduce);
        }else{
            $this->resolution = 4096;
        }
        $this->resolution = 4096 * (1/(1+$this->reduce));
        $this->_dir = HV_CACHE_DIR."/textures/";
        $this->_bmpDir = $this->_dir . "bmp/";
        $this->_jpgDir = $this->_dir . "jpg/";
        $this->createTextureCacheFolder();
    }

    public function getTexture(){
        $unixTime = (int)$this->_params["unixTime"];
        $sourceId = (int)$this->_params["sourceId"];
        $date = date('Y-m-d G:i:s',$unixTime);

        $imageData = $this->db->getDataFromDatabase($date, $sourceId);
        $jp2Filepath = HV_JP2_DIR.$imageData['filepath'].'/'.$imageData['filename'];
        $bmpFilename = substr($imageData['filename'],0,-4) . "_". $this->reduce .".bmp";
        $bmpFilepath = $this->_bmpDir . $bmpFilename;
        $jpgFilename = substr($imageData['filename'],0,-4) . "_". $this->reduce .".jpg";
        $jpgFilepath = $this->_jpgDir . $jpgFilename;
        if ( !@file_exists($jpgFilepath) ){//jpg is not cached
            if ( !@file_exists($bmpFilepath) ) {//bmp is not cached
                //extract bmp from jp2 file
                $jp2 = new Image_JPEG2000_JP2Image($jp2Filepath, 4096, 4096, 1);
                $jp2->extractRegion($bmpFilepath, $this->imageSubRegion, $this->reduce);
            }
            //load bmp cache and create new scaled jpg cache
            $imagickImage = new IMagick($bmpFilepath);
            $imagickImage->setImageFormat('jpg');
            header( "Content-Type: image/jpg" );
            echo $imagickImage->getImageBlob();
            $imagickImage->writeImage($jpgFilepath);
        }else{//scaled jpg exists, display it
            $this->outputFile = $jpgFilepath;
            $this->display();
        }
        

        // $imagickImage = new IMagick($bmpFilepath);
        // $imagickImage->resizeImage(4096,4096,imagick::FILTER_POINT,1);
        //$pngFilename = substr($imageData['filename'],0,-4) . ".png";
        //$pngFilepath = $this->_dir . $pngFilename;
        //$this->outputFile = $bmpFilepath;

        //$imagickImage->setImageFormat('jpg');
        //$imagickImage->setImageDepth(8);
        // Apply compression based on image type for those formats that
        // support it
        // Compression type
        //$imagickImage->setImageCompression(IMagick::COMPRESSION_LZW);

        // Compression quality
        //$imagickImage->setImageCompressionQuality(50);

        //$imagickImage->stripImage();
        //$imagickImage->setImageType(2);
        //$imagickImage->writeImage($this->outputFile);

        // header( "Content-Type: image/jpg" );
        // echo $imagickImage->getImageBlob();
        //$this->display();
    }

    private function createTextureCacheFolder(){
        if ( !@file_exists($this->_bmpDir) ) {
            if ( !@mkdir($this->_bmpDir, 0775, true) ) {
                throw new Exception(
                    'Unable to create directory: '. $this->_bmpDir, 50);
            }
        }

        if ( !@file_exists($this->_jpgDir) ) {
            if ( !@mkdir($this->_jpgDir, 0775, true) ) {
                throw new Exception(
                    'Unable to create directory: '. $this->_jpgDir, 50);
            }
        }
    }

    public function display() {

        //header('Cache-Control: public, max-age=' . $lifetime * 60);
        if ( function_exists('apache_request_headers') ) {
            $headers = apache_request_headers();
        }

        // Enable caching of images served by PHP
        // http://us.php.net/manual/en/function.header.php#61903
        $lastModified = 'Last-Modified: ' . gmdate('D, d M Y H:i:s',
            @filemtime($this->outputFile)) . ' GMT';

        if ( isset($headers['If-Modified-Since']) &&
             (strtotime($headers['If-Modified-Since']) ==
                @filemtime($this->outputFile))) {

            // Cache is current (304)
            header($lastModified, true, 304);
        }
        else {
            // Image not in cache or out of date (200)
            header($lastModified, true, 200);

            header('Content-Length: '.@filesize($this->outputFile));

            // Set content-type
            $fileinfo = new finfo(FILEINFO_MIME);
            $mimetype = $fileinfo->file($this->outputFile);
            header('Content-type: '.$mimetype);

            // Filename & Content-length
            $filename = basename($this->outputFile);

            header('Content-Disposition: inline; filename="'.$filename.'"');

            // Attempt to read in from cache and display
            $attempts = 0;

            while ($attempts < 3) {
                // If read is successful, we are finished
                if ( @readfile($this->outputFile) ) {
                    return;
                }
                $attempts += 1;
                usleep(500000); // wait 0.5s
            }

            // If the image fails to load after 3 tries, display an error
            // message
            throw new Exception('Unable to read image from cache: '.$filename, 33 );
        }
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute() {
        if ($this->validate()) {
            try {
                $this->{$this->_params['action']}();
            }
            catch (Exception $e) {
                handleError($e->getMessage(), $e->getCode());
            }
        }
    }

    /**
     * validate
     *
     * @return bool Returns true if input parameters are valid
     */
    public function validate() {

        switch( $this->_params['action'] ) {
            default:
                break;
        }
        // Check input
        if ( isset($expected) ) {
            Validation_InputValidator::checkInput($expected, $this->_params,
                $this->_options);
        }

        return true;
    }

}