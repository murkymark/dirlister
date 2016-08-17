<?php
/*
 dirlister.php

 Author: Markus Wolf (http://murkymind.de)
 License: zlib License (https://en.wikipedia.org/wiki/Zlib_License)
 Version: 2016-08-17
 Icons: from Silk Icons by Mark James (http://www.famfamfam.com) - https://creativecommons.org/licenses/by/2.5/
        partially modified
 Requires: ZipArchive class (>= PHP 5.2)

 This file must start with the PHP tag or response header cannot be modified!

 This script is a php directory lister with sorting features.
 Only relative pathes are used. A virtual root directory can be set.
 Files with special file name extensions can be filtered (hidden).
 Icon graphics can be set for specific filename extensions.
 Also consider using Apache "mod_autoindex" with "FancyIndexing" instead for better performance.
 It is recommended to only have ASCII file and dir names at the current state.

 * sort view by name, size, mdate, also reverse
 * click on a file to downloads it
 * click on a dir to view the dir's content by calling this script with a new path
 * special icon or path allows browsing the content of archives (ZIP) like a directory, supports extraction of files from archive (can be disabled)
 * special icon allows opening a file in external syntax higlighter script (can be disabled)

 Script GET parameters:
  'path': "..." (directory path from root dir without ending '/')
  'sortitem': "name","date","size" (sort attribute, "name" if sortitem is not set)
  'sortmode': "ascending","descending" (order)
  'forcex': =1 -> Enforce download of corrupt file from ZIP archive (CRC32 mismatch)

TODO:
 Show CRC when browsing archives
 Unicode support in dirs and ZIP
*/



/* Config section */
//###########################################################################


  //Root (with ending '/') of the directory tree is relative to the location of this PHP file
  //It's not possible to navigate to a lower directy by following links
  //Given root is virtual, independent from real document root
  //ROOTDIR directory is shown, if 'path' query is empty
  define("ROOTDIR", "./../../");

  //by manipulating the php query by hand one may view directories above virtual root dir or subdirs in upper directories
  // -> set to TRUE to avoid it, else set to FALSE
  //it blocks also pathes like "/../xyz","/xyz/../../ to make it look like a real root dir
  define("FORBID_ABOVE_ROOTDIR", TRUE);

  //format of the file dates, format string for "date()"
  define("DATE_FORMAT", "d-m-Y H:i:s");
  
  //date format info string at bottom (TODO: generate automatically from DATE_FORMAT)
  define("DATE_FORMAT_INFO", "dd-mm-yyyy hh:mm:ss");

  //filter files with following file name extensions:
  //(fill the array with the extension strings to filter, the extension is right beyond the last dot in a filename)
  //files in archives are not affected
  $FILE_FILTER = array('htaccess');
  #$FILE_FILTER = array('html','php');



  //dir icon
  define('ICON_DIR', 'folder.png');
  //default file icon
  define('ICON_FILE', 'page_white1.png');
  
  //fix icons
  define('ICON_ARCH', "package.png"); //icon for archive file
  define('ICON_VIEW_ARCH', 'folder.png'); //icon for browse archive file
  define('ICON_VIEW_FILE', 'magnifier.png'); //icon for file view (syntax highlighted text view or hex) >>> packet with magnifier
  
  //specific file icons
  //not used if undefined or empty, then default icon is used
  define('ICON_FILE_IMG' , 'image.png'); //image/graphic files
  define('ICON_FILE_ARCH', 'package.png'); //archive files
  define('ICON_FILE_AUD' , 'music.png'); //audio files
  define('ICON_FILE_TEXT', 'page_white_text1.png'); //text files


  //file name extension list for specific file types
  $FILE_EXT_IMG = array('png','jpg','jpeg','gif','bmp','ico');
  $FILE_EXT_ARCH = array('zip','rar','gz', 'bz2','tar', '7zip');
  $FILE_EXT_AUD = array('mp3','ogg','wav','flac');
  $FILE_EXT_TEXT = array('txt','nfo','c','cpp','h','hpp','cc','py','php','phps','htm','html','css','js','xml','json','bat','lua');
  


  //single files can be decompressed from archive files at first level
  define("VIEW_ARCH_MAXFILESIZE", 5000000); //files uncompressed bigger than this are forbidden to be extracted from archives
  define("VIEW_ARCH_ZIP", TRUE); //internal ZipArchive support enables content listing

  //allow viewing of files: content of archive files, syntax highlighted source code, etc.
  //may depend on external PHP code
  define("VIEW_FILE", TRUE);
  define("VIEW_FILE_SCRIPT", "../syntaxhl/syntaxhl.php"); //external PHP file
  
  
  //TODO maybe: add option to choose if dirs are sorted too (currently they are)

  //ZIP file path example:
  //browse zip file like a directory:
  //  http://murkymind.de/php/dirlister/dirlister.php?path=abc.zip/subdir/
  //extract file from archive:
  //  http://murkymind.de/php/dirlister/dirlister.php?path=abc.zip/foo.txt&extract=1
  //error, no file to extract given:
  //  http://murkymind.de/php/dirlister/dirlister.php?path=abc.zip&extract=1



/* Code section - functions */
//###########################################################################


  //function optimises a path string similar to "realpath()", but operates only on the given string
  // removes redundancies:
  //   path string "/../..//./" is changed to "/../.."
  //   path string "/abc/dir/../" is changed to "/abc"
  // path string "./" is changed to ""
  // the "/" at the end is kept
  function simple_path($path)
  {
      if(substr($path,-1) === '/')
        $last = '/';
      else 
        $last = '';
      
      $parts = explode(DIRECTORY_SEPARATOR, $path);

      //filter redundant "." and ""
      $t = array();
      foreach($parts as $p)
        if($p != "."  &&  $p != "")
          $t[] = $p;
      $parts = $t;

      //rebuild optimized to a new array
      $newparts = array();     //array in which "/../" is applied via $pos--
      $pos = 0;
      for($i = 0; $i < count($parts); $i++)    
      {
        if($parts[$i] == ".."  &&  $pos > 0  &&  $newparts[$pos-1] != "..")
          $pos--;  //one dir back
        else
          $newparts[$pos++] = $parts[$i];  //one dir forward
      }
      $newparts = array_slice($newparts, 0, $pos);  //only elements 0 to $pos are relevant, rest must be ignored

      //built string from array
      $newpath = "";
      foreach($newparts as $p)
        $newpath = $newpath . DIRECTORY_SEPARATOR . $p;

      return $newpath.$last;
  }


  //function checks the level of a given path string, return as int value
  // returns negative values if path level is above "/" (caused by "/../")
  // example: "/../" returns -1
  function path_level($path)
  {
    $path = simple_path( $path );  //to simplify e.g.: /./../

    $parts = explode(DIRECTORY_SEPARATOR, $path);
   
    $level = 0;
    foreach($parts as $p)
      if($p != "" && $p != ".")
      {
        if($p == "..") $level--;
        else           $level++;
      }    
    return $level;
  }


  //return TRUE if path accesses a dir above ROOTDIR, else FALSE
  function path_low_access($path)
  {
    $path = simple_path( $path );  //to simplify e.g.: /./../  (won`be correct), 

    $parts = explode(DIRECTORY_SEPARATOR, $path);

    $level = 0;
    foreach($parts as $p)
      if($p != "" && $p != ".")
      {
        if($p == "..") $level--;
        else           $level++;
        if($level < 0) return TRUE;
      }    
    return FALSE;
  }


  //remove prefix in string if found
  //$str: long string
  //$prefix: substring at the start of $str to remove
  function remove_prefix($str, $prefix)
  {
    if (substr($str, 0, strlen($prefix)) == $prefix)
      return substr($str, strlen($prefix), strlen($str));
  }

  //get virtual path from real relative path
  function get_vpath($rp){
    return remove_prefix($rp, ROOTDIR);
  }

  //ZipArchive error
  function zip_error($e){
    //echo "Zip error: todo";
    switch($e){
      case ZipArchive::ER_EXISTS:
        return "File already exists.";
        break;
      case ZipArchive::ER_INCONS:
        return "Zip archive inconsistent.";
        break;
      case ZipArchive::ER_INVAL:
        return "Invalid argument.";
        break;
      case ZipArchive::ER_MEMORY:
        return "Malloc failure.";
        break;
      case ZipArchive::ER_NOENT:
        return "No such file.";
        break;
      case ZipArchive::ER_NOZIP:
        return "Not a zip archive.";
        break;
      case ZipArchive::ER_OPEN:
        return "Can't open file.";
        break;
      case ZipArchive::ER_READ:
        return "Read error.";
        break;
      case ZipArchive::ER_SEEK:
        return "Seek error.";
        break;
      
      default:
        return "Unknown error";
        break;
    }
  
  }


  //extract single file from archive
  //better exit() right after calling that function, so download is not corrupted
  //$archp : path to archive file
  //$file  : file (path) inside archive
  function extract_zip($archp, $file)
  {
    //check if parameters are valid and file is not too large
    
    //Does archive path point to a file?
    if(!is_file($archp)){
      echo 'Error, "'.$archp.'" is not an archive file.';
      return;
    }
    
    //File to extract a subdir?
    if (substr($file, -1) == DIRECTORY_SEPARATOR){
      //the only way to download subdirs would be to decode and encode into a new zip file
      echo 'Error, cannot extract directory "'.$file.'" from archive. Only files can be extracted from archives!';
      return;
    }
    
    //open archive
    $z = new ZipArchive;
    $r = $z->open($archp, ZipArchive::CHECKCONS);
    if($r !== TRUE){
      echo 'ZIP error when trying to open "'.$archp.'"';
      echo '<br />';
      echo zip_error($r);
      return;
    }
    
    //File in archive?
    $stat = $z->statName($file);
    if($stat === FALSE) {
      echo 'Error: File "'.$file.'" not found in archive "'.$archp.'"';
      return;
    }

    //File to extract too large?
    if($stat['size'] > VIEW_ARCH_MAXFILESIZE) {
      echo 'Extraction of file "'.$file.'" denied. File size exceeds max. size limit of '.VIEW_ARCH_MAXFILESIZE.'';
      return;
    }

    $s = $z->getStream($file);
    if($s === FALSE){
      echo 'Failed to open stream to "'.$archp.'/'.$file.'"';
      return;
    }
    
    $forcex = 0;
    if(isset($_GET['forcex']))
      $forcex = $_GET['forcex'];
    
    //ZipArchive seems unable to detect CRC32 errors by itself
    //still we should detect/check before sending the data
    $data = stream_get_contents ($s, VIEW_ARCH_MAXFILESIZE);
    
    if($forcex != "1") {
      $crc_target = $stat['crc']; //should have for valid file
      $crc_have = crc32($data); //currently have
      
      if($crc_have != $crc_target){
        echo "Error: CRC32 mismatch of file \"" . $file . "\"!<br />";
        printf("CRC32 should be: %08X<br />", $stat['crc']);
        printf("CRC32 is: %08X<br />", $crc_have);
        printf("Size should be: %d B<br />", $stat['size']);
        printf("Size is: %d B<br />", strlen($data));
        echo '<a href=' . basename(__FILE__) . '?path=' . encode_spec_chars(get_vpath($archp) . '/' .$file) . '&extract=1&forcex=1>You can still download the corrupt file.</a>';
        die('');
      }
    }
    
    //if everything is ok set HTTP headers for download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename='.basename($file));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . strlen($data));
    
    
    ob_clean();
    //while(!feof($s)  &&  connection_status() == 0){
    //  echo fread($s, 1024*16);
    //}
    fclose($s);
    
    echo $data;
    $z->close();
  }


  //encode special chars so that nothing except ASCII or UTF8 appears
  //prevent problems with non ASCII chars in url, but don't encode slashes or space
  //use for links, which are invalid if not encoded
  function encode_spec_chars($str){
    if (mb_detect_encoding($str, 'ASCII, UTF-8', true) === FALSE){
      return str_replace('%20', ' ', str_replace('%2F', '/', rawurlencode($str)));
    }
    else
      return $str;
  }



/* Code section - main */
//###########################################################################


  //get sorting parameters or use default
  $sortitem = "name";
  $sortmode = "ascending";
  
  if(isset($_GET['sortitem'])){
    $sortitem = $_GET['sortitem'];
    if($sortitem != "name" && $sortitem != "date" && $sortitem != "size")
      $sortitem = "name";
  }
  
  if(isset($_GET['sortmode'])){
    $sortmode = $_GET['sortmode'];
    if(($sortmode != "ascending") && ($sortmode != "descending"))
      $sortmode = "ascending";
  }
  else {
    //if 'sortmode' not set
    switch($sortitem) {
      case "date":
        $sortmode = "descending";
        break;
      case "size":
        $sortmode = "descending";
        break;
    }
  }

  //get path parameter, the path relative to ROOTDIR
  if(isset($_GET['path']))
    $subdir = $_GET['path'];
  else
    $subdir = ".";

  $subdir = simple_path($subdir); //subdir is relative to rootdir
  $dir = "." . simple_path( ROOTDIR . DIRECTORY_SEPARATOR . $subdir );   //relative path from this script to target
 
  // subdir: path from rootdir to listed content (for printing and parameter only)
  // dir:    relative path from dir of this php file to the dir of the listed content



  if(isset($_GET['extract']))
    $do_extract = $_GET['extract'];
  else
    $do_extract = "0";


  if(FORBID_ABOVE_ROOTDIR === TRUE  &&  path_low_access($subdir) === TRUE)
    die("Forbidden, you don't have permission to access this path");


  //view inside archive file as dir
  $archfp = ""; //path to archive file
  $archsp = ""; //sub path to element in archive we want to browse (file name)
  $is_arch = FALSE; //indicates if path contains archive file
  
  //if path is a file, and not dir, search for first element which is a file and not dir
  $a = explode('/', $dir);
  foreach($a as $elem){
    $archfp .= $elem;
    if (is_file($archfp)){
      $archsp = remove_prefix($dir, $archfp . DIRECTORY_SEPARATOR); //path to element in archive we want to browse
      $is_arch = TRUE;
      break;
    }
    elseif (!is_dir($archfp)){
      die("Error: Invalid sub path: " . $archfp);
    }
    $archfp .= DIRECTORY_SEPARATOR;
  }

  if($do_extract > 0){
   extract_zip($archfp, $archsp);
   exit(); //quit here, additional output would corrupt download
  }
  
  
  // Everything below here is for content listing !!!


//text output must not start before and after file extraction/download!
echo <<<HEREDOC
<!DOCTYPE html>
<html>
 <head>
  <meta charset="utf-8" />
  
  <style type="text/css">
   body,td{font-family:arial,verdana; font-size:8pt}
   th{font-family:arial,verdana; font-size:8pt}
   
   tr.back{background-color:#eeeeee}
   tr.dir{background-color:#eeffff}
   tr.file{background-color:#ffffff}
   
   tr.back:hover{background-color:#aaffdd}
   tr.dir:hover{background-color:#aaffdd}
   tr.file:hover{background-color:#aaffdd}
   
   a:hover {color:crimson;}
   /* table a:hover {color:crimson;} */
   
   td {padding-right:20px}
   
   img {vertical-align: text-bottom;}
   
  </style>
HEREDOC;

  // make sure there is "/" at the end of path string
  if (substr($dir, -1) != DIRECTORY_SEPARATOR)
    $dir = $dir . DIRECTORY_SEPARATOR;
  if (substr($subdir, -1) != DIRECTORY_SEPARATOR)
    $subdir = $subdir . DIRECTORY_SEPARATOR;
    
  //print page title, open body, print headline, print sorting info, 
  echo " <title>Index of ".$subdir."</title>";
  echo "</head>";
  echo "<body>";
 
  //make each dir a link so quickly switching to a subdir is possible
  echo ' <h1>Index of "';
  $t = explode('/',$subdir);
  $link = basename(__FILE__).'?path=/';
  echo '<a href="'.basename(__FILE__).'?path=/">/</a>'; //root slash
  foreach($t as $e){
    if($e === '')
      continue;
    $e .= '/';
    $link .= encode_spec_chars($e);
    echo '<a href="'.$link.'">'.$e.'</a>';
  }
  echo '"</h1>'; 


  //[key => name] arrays for sorting by name
  $listdirs = array();
  $listfiles = array();

  //arrays containing stats of dirs and files, index is the key
  $stat_name = array();
  $stat_size = array();
  $stat_time = array();




  //ZIP note
  //- archive contains list of elements, which are files or dirs
  //  - dirs end with '/' and have a size of 0
  //- normally all dirs have a separate dir element
  //  - a missing separate dir element is probably also a valid archive file
  //    - here the dir is only indirectly referenced and we have to assume the attributes from the file entry
  //example elements - some dirs don't have separate entries:
  //  "myfile.txt" (file in root)
  //  "1/2/3/foo/ (dir in subdir)
  //  "2/3/binary.exe" (file in subdir)
  
  //if file is an archive, show a summary block with info about it
  if ($is_arch) {
    if (VIEW_ARCH_ZIP === TRUE  &&  strtolower(pathinfo($archfp, PATHINFO_EXTENSION)) == 'zip'){
      echo '<div style="background-color:#ECECEC;">';
      echo "<a title=\"download archive\" href=\"" . $archfp . "\"><img src=\"".ICON_ARCH."\" /> ZIP archive: \"/". remove_prefix($archfp, ROOTDIR) ."\"</a> (".filesize($archfp) ." B)<br />";
      $zip = new ZipArchive;
      $res = $zip->open($archfp, ZipArchive::CHECKCONS);
      
      if ($res !== TRUE) {
        echo 'Error: ' . zip_error($res);
        die("</div></body></html>");
      }
      
      //assume directory, files don't have a content to list
      if($archsp != ""  &&  substr($archsp, -1) != DIRECTORY_SEPARATOR)
        $archsp = $archsp . '/';

      $size = 0;  //size uncompressed, only content without ZIP header
      $size_comp = 0; //size compressed, only content without ZIP header
      
      $zipnfiles = 0; //number of files in ZIP archive
      $zipndirs = 0;  //number of dirs in ZIP archive
      $zipdirlist = array(); //we need to keep track of all encountered dirs for total count, also the indirectly given dirs
      
      $listdirs_temp = array();  //temporary dir list of indirect dirs
      
      //for all entries in ZIP archive
      $k = 0; //key
      for($i = 0; $i < $zip->numFiles; $i++){
        $stat = $zip->statIndex($i);
        $name = $stat['name'];
        
        $size += $stat['size'];
        $size_comp += $stat['comp_size'];
        
        //counting number of files/dirs
        //all path elements have size of 0 and end with '/'
        //dirs can be given indirectly with the file path: zipfile >> "a/b/myfile.txt" (2 dirs, 1 file)
        //at first we count all files and pathes, also duplicate pathes (pathes with filename stripped)
        if(substr($name, -1) != DIRECTORY_SEPARATOR) {
          array_push($zipdirlist, dirname($name) . DIRECTORY_SEPARATOR); //without filename
          $zipnfiles++;
        }
        else
          array_push($zipdirlist, $name);
         
        //check if $name (dir or file) is visible in the current directory view
        //only if path string starts with current subdir path, it contains visible elements
        $ps = $archsp; //path string, where we currently are in the file
        //ZIP entry names don't start with the following
        // "/" | "./" -> ""
        if($ps === DIRECTORY_SEPARATOR  ||  $ps === ('.' . DIRECTORY_SEPARATOR))
          $ps = '';
        

        //if element not in current path view -> skip (don't skip if element is in subdir of current view)
        if(substr($name, 0, strlen($ps)) != $ps)
          continue;
        
        //if element is current dir, skip
        if($ps == $name)
          continue;
        
        $subpath = remove_prefix($name, $ps);   //path relative to current dir view (without $ps part)

        //indirect dir?
        //if at least one delimeter is in $subpath, explode() returns array with at least 2 elements, name on [0]
        //1 => direct file ("myfile.txt")
        //2 => direct directory ("mydir/" or "mydir/myfile")
        //>2 => referenced directory, indirect directory maybe without stats (mydir/foo)
        $exp = explode(DIRECTORY_SEPARATOR, $subpath);   //current file path
        if(count($exp) > 2  ||  (count($exp) == 2  &&  $exp[1] != "")){
          $p = $archsp . $exp[0]; //path up to visible element
          
          //check if already in $listdirs or as separate element in ZIP archive
          if(!in_array($p . '/', $listdirs)  &&  $zip->locateName($p . '/') !== FALSE) {
            if(!in_array($p . '/', $listdirs_temp))
              //add to temp list -> above check ensure uniqeness
              $listdirs_temp[$i] = $p . '/';
          }
          continue;
        }
        

        $stat_size[] = $stat['size'];
        $stat_time[] = $stat['mtime'];

        $name_plain = remove_prefix($name, $ps); //name without dir part
        $stat_name[] = $name_plain;
        
        //directory
        if(count($exp) >= 2)
          $listdirs[$k] = $name_plain;
        //file 
        else   //count($exp) == 1
          $listfiles[$k] = $name_plain;
        
        $k++;
      }
      

      //if indirect dirs where found
      //we cannot just takeover the mtime from the current ZIP element, because there can be multiple sub elements (from which to take?)
      // => so we use mtime = 0
      $i = count($listdirs) + count($listfiles); //start with unused key
      foreach($listdirs_temp as $k => $v){
        $listdirs[$i] = $v;
        if(substr($v, -1) == DIRECTORY_SEPARATOR)
          $v = substr($v, 0, -1); //strip trailing "/"
        $stat_name[$i] = $v;
        $stat_size[$i] = 0;
        $stat_time[$i] = 0;
        $i++;
      }
      
      
      
      //remove duplicates
      $zipdirlist = array_unique($zipdirlist);
      
      //check for missing dir elements by exploding and rebuilding path strings
      foreach($zipdirlist as $v){
        $darr = explode(DIRECTORY_SEPARATOR, $v);
        $s = '';
        foreach($darr as $dpath){
          if($dpath == '') //skip '' caused by explode()
            continue;
          $s .= $dpath . DIRECTORY_SEPARATOR;
          if(!in_array($s, $zipdirlist))
            array_push($zipdirlist, $s);
        }
      }
      //remove "./"
      $k = array_search( '.' . DIRECTORY_SEPARATOR, $zipdirlist);
      if($k !== FALSE)
        unset($zipdirlist[$k]);
      $zipndirs = count($zipdirlist); //now contains all direct and indirect directories

      echo "# elements: " . $zip->numFiles . "<br />";
      echo "# files / directories: " . $zipnfiles . ' / ' . $zipndirs . "<br />";
      echo "Compressed content size: " . $size_comp . " B<br />";
      echo "Uncompressed content size: " . $size . " B<br />";
      if($size === 0)
        $ratio = 100; //contains only dirs or empty files
      else
        $ratio = 100 * $size_comp / $size;
      printf("Compression ratio: %.1f%%<br />", $ratio);
      echo "Archive comment: ";
      if(strlen($zip->getArchiveComment()) > 0)
        echo '<pre style="background-color: #DCDCDC; margin: 5px 5px 5px 5px;">' .  $zip->getArchiveComment() . "</pre>";
      else
        echo 'null';
      echo '</div><br />';
      

      //now check if $archsp contains valid path in archive
      if($archsp != ""  &&  $zip->locateName($archsp) === False) {
        
        //check if it's a valid file in archive but not a dir
        if(substr($archsp, -1) == DIRECTORY_SEPARATOR)
          $archsp = substr($archsp, 0, -1); //strip trailing "/"
         
        if($zip->getFromName($archsp)){
          if(substr($subdir, -1) == DIRECTORY_SEPARATOR)
            $subdir = substr($subdir, 0, -1); //strip trailing "/"
          echo ('<b>Error: Path points to a file in archive: "' . $archsp . '"</b><br />');
          echo ('Browse parent directory: <a href="' . basename(__FILE__) . '?path=' . dirname($subdir) . '">'.dirname($subdir).'</a><br />');
          $t = $zip->statName($archsp);
          $t = $t['size'];
          echo ('Download file: <a href="' . basename(__FILE__) . '?path=' . $subdir . '&extract=1' .'">'. $subdir .'</a> ('. $t .' B)<br />');
          $zip->close();
          die("</body></html>");
        }
        else {
          $zip->close();
          die("Error: Invalid path in archive file: \"" . $archsp . "\"</body></html>");
        }
      }

    }
    else
      die("Cannot browse file content, format unknown or viewer disabled</body></html>");
  }
  else {

    //try to open directory
    $handle = opendir($dir);
    if($handle == FALSE)
      die ("Error, can't read directory \"".$subdir."\"</body></html>");
    
    //read dir entries
    $i = 0;
    while (($file = readdir($handle)) !== FALSE)
    {
      if( $file != ".."  &&  $file != ".")
      {
        $stat_name[] = $file;
        $stat_size[] = filesize($dir.$file);
        $stat_time[] = filemtime($dir.$file);
        if(is_dir($dir.$file) == TRUE)
          $listdirs[$i] = $file;
        else {
          $pi = pathinfo( $dir.$file );
          if(in_array($pi['extension'], $FILE_FILTER) == FALSE)  //apply extension filter
            $listfiles[$i] = $file;
        }
        $i++;
      }
    }
    closedir($handle);
  }



  echo "Sorting: by " . $sortitem . ", " . $sortmode . "<br>";

  //prepare parameter line for each item re-sort link
  switch($sortitem){
    case "name":
      $pdate = "sortitem=date&sortmode=descending"; //default
      $psize = "sortitem=size&sortmode=descending"; //default
      if($sortmode == "ascending")
        $pname = "sortitem=name&sortmode=descending";
      else
        $pname = "sortitem=name&sortmode=ascending";
      break;
    
    case "date":
      $pname = "sortitem=name&sortmode=ascending";
      $psize = "sortitem=size&sortmode=descending";
      if($sortmode == "ascending")
        $pdate = "sortitem=date&sortmode=descending";
      else
        $pdate = "sortitem=date&sortmode=ascending";
      break;
      
    case "size":
      $pname = "sortitem=name&sortmode=ascending";
      $pdate = "sortitem=date&sortmode=descending";
      if($sortitem == "size"  &&  $sortmode == "ascending")
        $psize = "sortitem=size&sortmode=descending";
      else
        $psize = "sortitem=size&sortmode=ascending";
      break;
  }
  
  
  echo ' <table border="1" rules="groups" frame="void">';
  $t = basename(__FILE__)."?path=".$subdir."&";
  echo "  <thead><tr align=\"left\"><th><a title=\"sort by name\" href=\"". $t .$pname."\">Name</a></th><th><a title=\"sort by date\" href=\"". $t .$pdate."\">Last modified</a></th><th><a title=\"sort by size\" href=\"". $t .$psize."\">Size</a></th><th>View</th></tr></thead>";
  //echo " <tr><td colspan=3><hr></td></tr>";



 /*  sorting:
  *    We sort ascending and reverse the order if requested.
  *    The index is used as the key so we sort associative
  *    For equal 'date' or 'size', subsorting by 'name' is applied.
  */

  //sort ascending by name (default), this also provide a subsort by 'name' for 'date' and 'size'
  asort($listdirs);
  asort($listfiles);


  if($sortitem == "date")  //ascending sort means: newest first (biggest filemtime())
  {
    foreach($listdirs as $i => &$val)
      $val = $stat_time[$i];
    asort($listdirs, SORT_NUMERIC);

    foreach($listfiles as $i => &$val)
      $val = $stat_time[$i];
    asort($listfiles, SORT_NUMERIC);
  }
  elseif($sortitem == "size") //ascending sort means: biggest first
  {
    //dir size is always 0, so keep them sorted by name)
    
    foreach($listfiles as $i => &$val)
      $val = $stat_size[$i];
    asort($listfiles, SORT_NUMERIC);
  }


  //revert if requested
  if($sortmode == "descending") {
      $listfiles = array_reverse($listfiles, TRUE);  //TRUE -> preserve key->value mapping
      //for 'size' don't revert the alphabetical order of dirs
      if($sortitem != "size")
        $listdirs = array_reverse($listdirs, TRUE);
  }


   /*
    *  List dirs and files
    */

    $param = "&sortitem=".$sortitem."&sortmode=".$sortmode;


    //set <parent directory> entry "..", always on top
    if(path_level($subdir) > 0) {
      $link = basename(__FILE__)."?path=" . encode_spec_chars($subdir) . "../" . $param;
      echo "<tr class=\"back\">\n<td><b><a title=\"open parent directory\" href=\"".$link."\">..</a></b></td><td></td><td></td><td></td>\n</tr>\n";
    }

    //list dirs
    $icon = ICON_DIR;
    foreach($listdirs as $k => $v){
      $name = $stat_name[$k];
      if(substr($name, -1) == DIRECTORY_SEPARATOR)
        $name = substr($name, 0, -1); //strip trailing "/"
      $link = basename(__FILE__)."?path=" . encode_spec_chars($subdir.$name) . $param;
      echo "<tr class=\"dir\">\n<td><b><a title=\"open directory\" href=\"".$link."\"><img src=\"".$icon."\" align=bottom/>".$name."</a></b></td><td>".date(DATE_FORMAT, $stat_time[$k])."</td> <td></td> <td></td>\n</tr>\n";
    }

    //list files
    
    //if currently inside archive
    if($is_arch)
      $title = 'open/extract file';
    else
      $title = 'open file';
      
    $totsize = 0;
    foreach($listfiles as $k => $v)
    {
      $name = $stat_name[$k];

      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if(defined('ICON_FILE_TEXT')  &&  strlen(ICON_FILE_TEXT) > 0  &&  in_array($ext,$FILE_EXT_TEXT))
        $icon = ICON_FILE_TEXT;
      elseif(defined('ICON_FILE_ARCH')  &&  strlen(ICON_FILE_ARCH) > 0  &&  in_array($ext,$FILE_EXT_ARCH))
        $icon = ICON_FILE_ARCH;
      elseif(defined('ICON_FILE_IMG')  &&  strlen(ICON_FILE_IMG) > 0  &&  in_array($ext,$FILE_EXT_IMG))
        $icon = ICON_FILE_IMG;
      elseif(defined('ICON_FILE_AUD')  &&  strlen(ICON_FILE_AUD) > 0  &&  in_array($ext,$FILE_EXT_AUD))
        $icon = ICON_FILE_AUD;
      else
       $icon = ICON_FILE;

      $link_param_path = "?path=" . encode_spec_chars($subdir.$name);
      if($is_arch)
        $link = basename(__FILE__) . $link_param_path . "&extract=1";
      else
        $link = encode_spec_chars($dir.$name);
      echo "<tr class=\"file\">\n<td><a title=\"".$title."\" href=\"".$link."\"><img src=\"".$icon."\" align=\"middle\" /> ".$name."</a></td><td>".date ( "d-m-Y H:i:s", $stat_time[$k] )."</td><td align=\"right\">". (int) round( $stat_size[$k] / 1024 ) ." kib</td><td>";

      //view file
      echo '<a title="view file" href="'.VIEW_FILE_SCRIPT.$link_param_path.'"><img src="'.ICON_VIEW_FILE.'" /></a>';
      if(VIEW_ARCH_ZIP === TRUE  &&  !$is_arch  &&  $ext == 'zip')
        //browse archive
        echo ' <a title="browse archive" href="' . basename(__FILE__) . $link_param_path . '/"><img src="'.ICON_VIEW_ARCH.'" /></a>';
      echo "</td>\n</tr>\n";
      $totsize += $stat_size[$k]; 
    }

    //summary
    echo " <tfoot><tr><td colspan=3></td></tr></tfoot>";
    //echo " <tr><td colspan=3><hr></td></tr>";
    echo " </table>";
    echo count($listfiles) . " files (". (int) ($totsize / 1024) ."kib), " .  count($listdirs) . " directories";
    echo "<br><br>Date format: [" . DATE_FORMAT_INFO . "]";

?>

</body>
</html>
