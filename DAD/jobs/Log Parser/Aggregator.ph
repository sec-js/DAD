$Groomer_Hour = 0;	# 12 AM
$TZ_Offset = 7; 	#DST
$Stat_Time_Period = (12*60*60); #12 hours
$OUTPUT_LOCATION = "c:/dad/web/html/stats";
$BackupSQLFile = "c:/dad/jobs/log parser/SQL_Contents.log"
$MAX_EXECUTION_TIME = 30;
$DEBUG=0;
$MYSQL_SERVER="localhost";
$MYSQL_USER="root";
$MYSQL_PASSWORD="All4Fun";
$Total_Run_Time = 3600;		# Maximum time we will run. -- Currently deprecated
$EVENT_HANDLER_THREADS = 8;	#Number of threads processing event logs
$INSERT_THREADS=4;			#Number of threads inserting events
$MAX_QUEUE_SIZE=5000;
$MAX_IDLE_LOOPS=15;
$MAX_UNIQUE_STRINGS=200000;