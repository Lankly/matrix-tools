<?php
/*
  Plugin Name: IACP Data Migration
  Plugin URI:  http://github.com/Lankly/matrix-tools/tree/iacp-data-migration
  Description: Migrates all the issues of Police Chief Magazine to WordPress.
  Version:     0.1
  Author:      Matrix Group International
  Author URI:  http://matrixgroup.net/
  License:     GPL2
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
  Text Domain: iacp-migration
  Domain Path: /languages
*/

define("DEBUG", true); /* Set to false to disable all other DEBUGs. */
define("DEBUG_CREDS", DEBUG && true); /* Set to true to echo serv, un, pw. */
define("DEBUG_DATA", DEBUG && false); /* Set to true to echo all the data. */
define("DEBUG_FIRST_LINE", DEBUG && true);
                     /* Set to true to echo all the fields of the first line. */

define("FIELD_REQUIRED", true);
define("FIELD_NOT_REQUIRED", false);
define("COLUMN_NOT_FOUND", -1);

if(DEBUG_CREDS){
    echo "Credentials debugging enabled." . PHP_EOL;
}
if(DEBUG_DATA){
    echo "Data debugging enabled." . PHP_EOL;
}
if(DEBUG_FIRST_LINE){
    echo "First-line debugging enabled." . PHP_EOL;
}
if(DEBUG){
    echo PHP_EOL;
}

/* This class is basically just a struct for holding information about field
 * names and where they are.
 */
class IACPField {
    public $orig_name; /*Field in the csv*/
    public $new_name;  /*Field in the WP db*/
    public $required;  /*Whether or not the field can be blank*/
    public $col_num;   /*Which column in the data is this field*/
    function __construct($orig_name, $new_name, $req){
        $this->orig_name = $orig_name;
        $this->new_name = $new_name;
        $this->required = $req;
        $this->col_num = COLUMN_NOT_FOUND;
    }
}

/* Every field in this array is one that will be mapped to a field in the new
 * database. If any of the fields still have the default value after the file
 * has been read, the program should fail.
 */
$iacp_fields_array = [
    new IACPField("Article Title", "Article Title", FIELD_REQUIRED),
    new IACPField("Article Text", "Article Text", FIELD_REQUIRED),
    new IACPField("Author", "Author", FIELD_REQUIRED),
    new IACPField("Issue Date", "Issue Date", FIELD_REQUIRED),
    new IACPField("Description", "Description", FIELD_REQUIRED),
    new IACPField("Volume Number", "Volume Number", FIELD_REQUIRED),
    new IACPField("Issue Number", "Issue Number", FIELD_REQUIRED),
    new IACPField("Cover Image", "Cover Image", FIELD_REQUIRED)
];

/* Handles all of reading a given first line of a csv file to figure out where
 * each of our fields are in order.
 */
function iacp_read_first_line(string $fl){
    if(empty($fl)){
        die("First line is empty!");
    }

    if(DEBUG_FIRST_LINE){
        echo "Raw first line: " . $fl . PHP_EOL;
    }
    
    //Look at each individual element of the csv
    foreach (explode(",", $fl) as $index => $fieldname){
        //Loop through the array of fields
        foreach((array)$iacp_fields_array as $field){
            
            if(DEBUG_FIRST_LINE){
                echo "Field " . $index . ": " . $fieldname . PHP_EOL;
            }
            
            //And if it's there, set it
            if(strcmp(strtolower(trim($field->orig_name)),
                      strtolower(trim($fieldname))) == 0){
                $field->col_num = $index;
                
                break;
            }
        }
    }

    //Now make sure every required field was there
    foreach((array)$iacp_fields_array as $field){
        if($field->col_num == FIELD_REQUIRED){
            die("Didn't find " . $field.orig_name . ".");
        }
    }
}

/* This function should only ever be called after iacp_read_first_line. It
 * expects the col_num field of the 
 */
function iacp_format_line($line){
    if(!$line){
        return ["Empty line."];
    }

    //Put each field into its correct place in the new array
    $fields = explode(",", $line);
    $to_return = [];
    foreach((array)$iacp_fields_array as $i){
        if($i->COLUMN_NOT_FOUND){
            continue;
        }
        
        $tmp = $fields[$i->col_num];

        //If this field was empty, check to see if that was okay
        if(($tmp == "" || $tmp == null) && $i->required){
            die("Required field " . $i->orig_name . " could not be found!");
        }

        $to_return[] = $tmp;
    }

    return $to_return;
}


$iacp_serv = ""; $iacp_db = ""; $iacp_un = ""; $iacp_pw = "";
/* Sets the above variables to their corresponding entry in the file.
 */
function iacp_read_credentials(){
    $filename = "credentials.txt";
    $f = fopen($filename, "r") or die("Unable to open file!");

    if(DEBUG_CREDS){echo PHP_EOL . "Beginning read..." . PHP_EOL;}

    //SERVER 
    $line = fgets($f) or die("Credentials empty!");
    //No need for a PHP_EOL on this echo since $line will contain a newline
    if(DEBUG_CREDS){echo substr($line, 0, strlen($line) - 2);}
    $index = strpos($line, ":");
    $iacp_serv = trim(substr($line, $index + 1));
    if(DEBUG_CREDS){echo " (" . $iacp_serv . ")" . PHP_EOL;}

    //DATABASE
    $line = fgets($f) or die("Credentials empty!");
    if(DEBUG_CREDS){echo substr($line, 0, strlen($line) - 2);}
    $index = strpos($line, ":");
    $iacp_db = trim(substr($line, $index + 1));
    if(DEBUG_CREDS){echo " (" . $iacp_db . ")" . PHP_EOL;}
    
    //USERNAME
    $line = fgets($f) or die("Missing username!!");
    if(DEBUG_CREDS){echo substr($line, 0, strlen($line) - 2);}
    $index = strpos($line, ":") or die("Invalid username!");
    $iacp_un = trim(substr($line, $index + 1));
    if(DEBUG_CREDS){echo " (" . $iacp_un . ")" . PHP_EOL;}

    //PASSWORD
    $line = fgets($f) or die("Missing password!!");
    if(DEBUG_CREDS){echo substr($line, 0, strlen($line) - 2);}
    $index = strpos($line, ":") or die("Invalid password!");
    $iacp_pw = trim(substr($line, $index + 1));   
    if(DEBUG_CREDS){echo " (" . $iacp_pw . ")" . PHP_EOL;}

    //If we were printing out stuff, space it nicely.
    if(DEBUG_CREDS){echo "Done read." . PHP_EOL . PHP_EOL;}
}

/* We're going to grab the first csv file in this directory and break it up into
 * something we can use. This function is separate so that if we want to turn it
 * into something that performs queries directly on a database, we wouldn't have
 * to change any other functions.
 *
 * Returns an array of arrays of strings that represent all the data we are
 * trying to move.
 */
function iacp_get_data(){
    $to_return = [];
    
    iacp_read_credentials();
    
    return $to_return;
}

function iacp_migrate(){
    $data = iacp_get_data();
    $counter = 1;
    
    foreach((array)$data as $line){
        if(DEBUG_DATA){
            echo "Line " . $counter . ":" . PHP_EOL;
        }
        foreach((array)$line as $field){
            if(DEBUG_DATA){
                echo $field . PHP_EOL;
            }
        }
        $counter++;
    }
}

iacp_migrate();

echo "Done.";

exit;

?>