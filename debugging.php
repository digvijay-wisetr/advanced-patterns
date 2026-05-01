Reproducing Production Bugs
01-reproduce-prod.md## Steps
1. Import anonymized DB   mysqldump prod_db > dump.sql   (remove emails, passwords)
2. Enable debug   define('WP_DEBUG', true);   define('WP_DEBUG_LOG', true);
3. Replay webhook (Stripe example)   stripe listen --forward-to localhost:8000/wp-json/webhook4. 
Use same:   - currency   - user role   - cart itemsIf bug not reproduced → you're not testing correctly.

2. git bisect (Find Breaking Commit FAST)
# 02-git-bisect.mdgit bisect startgit bisect bad        
# current broken stategit bisect good HEAD~5
# Git checks out commit → test your sitegit bisect good       
# if worksgit bisect bad       
# if broken# repeat until:
OUTPUT:
    <commit-hash> is the first bad commitgit bisect reset
This saves hours vs reading diffs.

3 - Xdebug Profiler (Local Performance)
# 03-xdebug-profile.md# php.inixdebug.mode=profilexdebug.output_dir=/tmp/xdebug# Run request → open output in:# QCacheGrind / WebgrindLook for:- functions with high time- repeated DB calls

4 - Plugin Conflict Isolation
Use: Health Check & Troubleshooting
# 04-plugin-conflict.mdSteps:1. Install Health Check plugin2. Enable Troubleshooting Mode3. Only YOU see disabled plugins4. Enable plugins one by oneGoal:Find which plugin breaks checkout/payment

5 - MySQL Slow Query Log
slow-query.sqlSET 
GLOBAL slow_query_log = 'ON';SET GLOBAL long_query_time = 1;
-- check log file
SHOW VARIABLES LIKE 'slow_query_log_file';

Example bad query pattern:
SELECT * FROM wp_postmeta WHERE meta_key = '_price';
runs on every request → needs indexing/caching

6 - Object Cache Inspection
Use: Query Monitor
 
add_action('init', function(){   
     wp_cache_set('test_key', 'value');    
     $value = wp_cache_get('test_key');   
      error_log('Cache Value: ' . $value);
});



Check Cache Hits vs Misses


7 - Stack Trace Reading (Core Skill)

function level1(){   
     level2('test');
}
function level2($arg){    
    level3($arg);
}
function level3($arg){    
    throw new Exception("Error with arg: " . $arg);
}level1();

Example Output
Fatal error: Uncaught 
Exception: Error with arg: testStack 
trace: 
#0 level3('test') 
#1 level2('test')
#2 level1()

How to Read

Error happened in → level3

Argument passed → 'test'

Call chain → level1 → level2 → level3

This is exactly how you debug WooCommerce/payment issues.

8 - README.md (Short + Strong)

# Production Debugging Patterns (WooCommerce)

## Covers :- Reproducing production bugs locally - git bisect for finding breaking commits - Profiling with Xdebug - Plugin conflict isolation - MySQL slow query logging - Object cache debugging - Stack trace analysis

## Key Learnings - If you can't reproduce → you can't fix- Never guess performance → always profile- Debug systematically, not randomly

