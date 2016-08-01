/* Converts the best bets given to us by ELFA to the format that Searchblox 
 * expects.
 */
require("jsdom").env("", function(err, window) {
    if (err) {
        console.error(err);
        return;
    }

    var fs = require("fs");
    var $ = require("jquery")(window);
    
    const readline = require('readline');
    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout
    });

    
    /* startDate| keywords| status| imageUrl| expiryDate| useDates| type| url| keywordsFieldType| expiryCount| title| priority| description| keywordsUrl
     */
    function format(arr){
        sb_format = [
            "start_date",
            "keywords",
            "status",
            "image_url",
            "expiry_date",
            "use_dates",
            "type",
            "url",
            "keywords_field_type",
            "expiry_count",
            "title",
            "priority",
            "description",
            "keywords_url"
        ];

        var lines = [];
        lines.push(sb_format.join(" | ").replace(/_(\S)/g, function(_,x){
            return x.toUpperCase();
        }));
        arr.forEach(function(elem){
            var formatted = "";
            
            for(var i = 0; i < sb_format.length; i++){
                formatted += elem[sb_format[i]] || "<nil>";
                formatted.trim();
                formatted += " | ";
            }
            formatted = formatted.slice(0, -3);
            /*/Everything gets quotes if it contains commas
            if(formatted.indexOf(",") > 0){
                formatted = '"' + formatted.trim() + '"';
            }/**/
            lines.push(formatted);
        });

        writeFormattedCSV(lines);
    }

    /* Takes in an array of objects, each of which contains a key called "link".
     * It will prompt the link to the user, whose job it is to follow that link
     * and type back the title that they see. When this is done, it will call
     * the format() function.
     */
    function getTitles(arr, count){
        var arr_loc = arr;
        if(!arr_loc || arr_loc == []){
            quit("Empty or nonexistant arr_locay!");
        }
        if(count == undefined){
            count = 0;
        }
        if(arr_loc[count] == undefined || count >= arr_loc.length){
            format(arr_loc);
            return;
        }
        else if(arr_loc[count].title != undefined
                && arr_loc[count].title != ""){
            getTitles(arr_loc, count + 1);
            return;
        }

        //Same url can be in multiple objects, so loop through to find
        arr_loc.forEach(function(elem){
            if(elem != undefined && elem.url == arr_loc[count].url
               && elem.title != ""
               && elem.title != "<nil>"){
                arr_loc[count].title = elem.title;
                getTitles(arr_loc, count + 1);
                return;
            }
        });

        //Otherwise, prompt
        if(arr_loc[count] != undefined &&
           arr_loc[count].title == "" || arr_loc[count].title == "<nil>"){
            rl.question("(" + count + ") What is the title of page '" +
                        arr_loc[count].url + "'?\n",
                        function(answer){
                            arr_loc[count].title = answer;
                            
                            getTitles(arr_loc, count + 1);
                            return;
                        });
        }
        format(arr_loc);
    }

    function quit(msg){
        console.log(msg || "Done");
        
        rl.close();
        process.exit();
    }
    
    function readInBestBets(err, data){
        if(err){
            return console.log("Error: " + err);
        }

        //default format
        var format = {
            keywords: -1,
            url: -1,
            title: -1,
            description: -1
        }
        
        //Lines may be formatted differently, so figure out the formatting from
        //the first line
        var lines = (""+data).split("\n");
        var form_arr = lines[0].split(",");
        lines.shift();
        for(var i = 0; i < form_arr.length; i++){
            var lower = form_arr[i].toLowerCase();

            //Aliases
            if(lower.indexOf("keyword") > 0
               || lower.indexOf("term") > 0){
                lower = "keywords";
            }
            else if(lower.indexOf("url") > 0
                    || lower.indexOf("link") > 0){
                lower = "url";
            }
            else if(lower.indexOf("desc") > 0){
                lower = "description";
            }
            else if(lower.indexOf("title") > 0
                    || lower.indexOf("name") > 0){
                lower = "title";
            }
            
            if(format[lower] != undefined){
                format[lower] = i;
            }
        }
        

        //Put each line into a javascript object
        var arr = [];
        lines.forEach(function(line){

            var cur_col = 0;
            var obj = {
                "expiry_count": "-1",
                "keywords_field_type": "STRING",
                "priority": "A",
                "status": "active",
                "type": "TEXT",
                "use_dates": "false"
            };

            while(line != ""){
                var cur = "<nil>";
                var form_keys = Object.keys(format);
                
                //Base case: last object
                if(cur_col == form_keys.length - 1){
                    cur = line;
                    line = "";
                }
                //Next term surrounded by quotes?
                else if(line.slice(0,1) == '"'){
                    cur = line.substring(1, line.substring(1).indexOf('"')+1);
                    line = line.substring(line.slice(1).indexOf('"') + 2);
                    line = line.substring(line.indexOf(",") + 1);
                }
                //Otherwise, just grab until next comma
                else{
                    cur = line.substring(0, line.indexOf(","));
                    line = line.substring(line.indexOf(",") + 1);
                }
                line = line.trim();

                //Remove surrounding quotes, any pipes
                cur = cur.replace(/^"/, "");
                cur = cur.replace(/"$/, "");
                cur = cur.replace(/\|/g, "-");

                //Add to object
                for(var i = 0; i < form_keys.length; i++){
                    if(i == cur_col){
                        //Special case for keywords
                        if(form_keys[i] == "keywords"){
                            cur = removeDups(cur);
                        }

                        obj[form_keys[i]] = cur;
                    }
                }
                cur_col++;
            }

            //Ignore blank and to-be-determined
            if(obj.url != undefined && obj.url.toLowerCase() != "tbd"
              && obj.url != ""){
                arr.push(obj);
            }
        });
        getTitles(arr);
    }

    /* Takes in a string and returns a string containing every unique 
     * alphanumeric word in the original.
     */
    function removeDups(str){
        if(str == undefined){
            return "";
        }

        var commonWords = [
            "a",
            "an",
            "and",
            "in",
            "it",
            "of",
            "or",
            "my",
            "the",
            "you",
            "your"
            ]

        function onlyUnique(value, index, self) {
            return self.indexOf(value) === index
                && /[a-z0-9]+/i.test(value)
                && commonWords.indexOf(value) == -1;
        }

        var sepBySpaces = str.replace(/,/g, " ");
        var uniques = sepBySpaces.split(" ").filter(onlyUnique);
        
        return uniques.sort().join(" ");
    }

    function writeFormattedCSV(arr){
        if(arr == undefined || arr == []){
            quit("Arr not provided or empty");
        }

        var path = "best-bets/sb-bb.csv";
        rl.question("Save file path: ", function(answer){
            path = !answer ? path : answer;

            var formatted = arr.join("\n");
            
            fs.writeFile(path, formatted, function(err){
                if(err){quit(err);}
                quit("Done!");
            });
        });
        
    }
    
    /* Starts the process of converting the csv.
     */
    function convert(){
        //default path
        var path = "best-bets/best-bets.csv";
        rl.question("File path: ", function (answer) {
            path = !answer ? path : answer;

            //Now read the file in
            fs.readFile(path, readInBestBets);
        });        
    }

    convert();
   
});

