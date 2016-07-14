/* This file should be used as the base for any quick node work. Just add your
 * code into the require statement and anything inside it will run. End your
 * program with a quit statement.
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

    
    function quit(msg){
        console.log(msg || "Done");
        
        rl.close();
        process.exit();
    }


    quit();
    
});

