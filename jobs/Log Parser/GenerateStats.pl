#!/usr/bin/perl
#   DAD Log Aggregator
#    Copyright (C) 2014, David Hoelzer/Cyber-Defense.org
#
#    This program is free software; you can redistribute it and/or modify
#    it under the terms of the GNU General Public License as published by
#    the Free Software Foundation; either version 2 of the License, or
#    (at your option) any later version.
#
#    This program is distributed in the hope that it will be useful,
#    but WITHOUT ANY WARRANTY; without even the implied warranty of
#    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#    GNU General Public License for more details.
#
#    You should have received a copy of the GNU General Public License
#    along with this program; if not, write to the Free Software
#    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA

# The previous stats tracking was all generated by the aggregator and only generated in connection with Windows event logs.
# In an effort to provide some log graphing without rewriting everything I've decided to create this sort of kludge that
# leverages the pre-existing table, possibly using it in a way that was not originally intended (since I frankly don't remember
# how I intended the fields to be used!)

# Modules for DB and Event logs.  POSIX is required for Unix time stamps
use DBI;
use POSIX;
use GD::Graph::lines;

#Read in and evaluate the configuration values that the Aggregator uses
open(FILE,"Aggregator.ph") or die "Could not find configuration file!\n";
foreach (<FILE>) { eval(); }
close(FILE);

$dsn = "DBI:mysql:host=$MYSQL_SERVER;database=dad";
$dbh = DBI->connect ($dsn, "$MYSQL_USER", "$MYSQL_PASSWORD")
	or die ("Could not connect to DB server to import the list of servers to poll.\n");

$start = int(shift);
$end = $start + 3600;
@Systems = &get_systems();
foreach(@Systems) { 
	$system_name = &get_system_name($_);
	if($system_name =~ /:/) {next;}
	$numevents = &get_num_events($_,$start, $end);
	$insert = "insert into dad_sys_event_stats (System_Name, Number_Inserted, Stat_Time) values ('$system_name', $numevents, $end)";
	&SQL_Insert($insert);
}

$graph_end_time = time();
$graph_start_time = $graph_end_time - 60*60*24*7;
foreach(@Systems) {
	my @Times = ();
	my @Events = ();
	
	$results_ref = &SQL_Query("select System_Name from dad_sys_systems where System_ID=$_");
	$row = shift(@$results_ref);
	$system_name = $row[0][0];
	$sql = "select Number_Inserted,Stat_Time from dad_sys_event_stats where System_Name='$_' and $graph_start_time>Stat_Time";
	$results_ref = &SQL_Query($sql);
	while($row = shift(@$results_ref))
	{
		@this_row = @$row;
		unshift(@Times, &_get_time_string($this_row[1],1));
		unshift(@Events, $this_row[0]);
	}
	my $points = @Times;
	if($points)
	{
		my $graph = GD::Graph::lines->new(700, 100);
		$graph->set(
			title				=> "$system_name Event/Insert Rate",
			x_label_position	=> 0.5,
			line_width			=> 1,
			x_label_skip		=> int($points/10),
			x_labels_vertical	=> 1
		) or die $graph->error;
		my @Data=([@Times],[@Events]);
		$graph->set_title_font('/fonts/arial.ttf', 24);
		my $gd = $graph->plot(\@Data) or die $graph->error;
		open(IMG, '>'.$OUTPUT_LOCATION."/$system_name.gif") or die $!;
		binmode IMG;
		print IMG $gd->gif;
		close IMG;
	}
}

#Aggregate
my @Times = ();
my @Events = ();

$sql = "select Number_Inserted,Stat_Time from dad_sys_event_stats where $graph_start_time>Stat_Time";
$results_ref = &SQL_Query($sql);
while($row = shift(@$results_ref))
{
	@this_row = @$row;
	#print $this_row[0]." -> ".$this_row[1]."\n";
	unshift(@Times, &_get_time_string($this_row[1],1));
	unshift(@Events, $this_row[0]);
}
my $points = @Times;
if($points)
{
	my $graph = GD::Graph::lines->new(700, 100);
	$graph->set(
		title				=> "Aggregate Events/Insert Rate",
		x_label_position	=> 1,
		line_width			=> 1,
		x_label_skip		=> int($points/5),
		x_tick_offset       => 1,
		x_labels_vertical	=> 1
	) or die $graph->error;
	my @Data=([@Times],[@Events]);
	$graph->set_title_font('/fonts/arial.ttf', 24);
	my $gd = $graph->plot(\@Data) or die $graph->error;
	open(IMG, '>'.$OUTPUT_LOCATION.'/Aggregate.gif') or die $!;
	binmode IMG;
	print IMG $gd->gif;
	close IMG;
}

sub get_system_name
{
	$ID = shift;
	$results_ref = &SQL_Query("select System_Name from dad_sys_systems where System_ID=$ID");
	@row = shift(@$results_ref);
	return $row[0][0];
}
sub get_num_events
{
	($system, $start, $end) = @_ or die("Wrong number of arguments to get_num_events:  system name, start time, end time\n");
	$results_ref = &SQL_Query("select count(*) from events where System_ID=$system and (($start<Time_Generated and $end>Time_Generated) or ($start<Time_Written and $end>Time_Written))");
	@row = shift(@$results_ref);
	return $row[0][0];
}

sub _get_time_string
{
	my @months = qw(Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec);
	my @weekDays = qw(Sun Mon Tue Wed Thu Fri Sat Sun);
	my ($second, $minute, $hour, $dayOfMonth, $month, $yearOffset, $dayOfWeek, $dayOfYear, $daylightSavings) = localtime(shift);
	my$year = 1900 + $yearOffset;
	my $theTime;
	if(shift())
	{
		$theTime = ($month+1)."/".($dayOfMonth+1);
	}
	else
	{
		$theTime = ($hour<10 ? "0$hour" : "$hour").":".($minute<10? "0$minute" : "$minute");
	}
	return $theTime;
}

##################################################
#
# SQL_Insert - Does the legwork for all SQL inserts including basic error checking
# 	Takes a SQL string as an argument
#
##################################################
sub SQL_Insert
{
	my $SQL = $_[0];
	my $query = $dbh->prepare($SQL);
	if($DEBUG){return; print"$SQL\n";return;}
	$query -> execute();
	my $in_id = $dbh->{ q{mysql_insertid}};
	$query->finish();
	undef $query;
	return $in_id;
}

sub get_systems
{
	my	$results_ref,				# Used to hold query responses
		$row,						#Row array reference
		@this_row;					#Current row
	my @Systems;


	# Fetch the names of the systems to poll.
	# There is no need to restart this process to pick up the new system names or remove old names.
	$results_ref = &SQL_Query("select distinct System_ID from events where Time_Generated>UNIX_TIMESTAMP(NOW())-86400");
	# Populate the @Systems array
	while($row = shift(@$results_ref) )
	{
		@this_row = @$row;
		 if ($this_row[0] !~ /:/) { unshift(@Systems, $this_row[0]); }
	}
	return(@Systems);
}

sub _get_time_string
{
	my @months = qw(Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec);
	my @weekDays = qw(Sun Mon Tue Wed Thu Fri Sat Sun);
	my ($second, $minute, $hour, $dayOfMonth, $month, $yearOffset, $dayOfWeek, $dayOfYear, $daylightSavings) = localtime(shift);
	my$year = 1900 + $yearOffset;
	my $theTime;
	if(shift())
	{
		$theTime = ($month+1)."/".($dayOfMonth+1);
	}
	else
	{
		$theTime = ($hour<10 ? "0$hour" : "$hour").":".($minute<10? "0$minute" : "$minute");
	}
	return $theTime;
}
##########################
# Grabs the raw data for each system.
sub _get_system_stat_data
{
	my	$results_ref,				# Used to hold query responses
		$row,						#Row array reference
		@this_row;					#Current row
	my $system, $Log_Size, $Inserted, $ALog, $AInserted, $Service;
	my $Time_Period;
	($system,$Log_Size,$Inserted, $ALog, $AInserted, $Service, $Time_Period)=@_ or die("Incorrect arguments to _get_system_stat_data.\n");

	$dsn = "DBI:mysql:host=$MYSQL_SERVER;database=dad";
	$dbh = DBI->connect ($dsn, "$MYSQL_USER", "$MYSQL_PASSWORD")
		or die ("Could not connect to DB server to import the list of servers to poll.\n");

	$Time_Period = time()-$Time_Period;
	my $SQL = "SELECT Total_In_Log,Number_Inserted,Stat_Time,Service_Name FROM dad_sys_event_stats WHERE Service_Name='$Service' AND System_Name='$system' AND Stat_Time>$Time_Period ORDER BY Stat_Time";
	$results_ref = &SQL_Query($SQL);
	my $last_logged = -1;
	while($row = shift(@$results_ref) )
	{
		@this_row = @$row;
		my $this_time = $this_row[2];
		$this_time = int($this_time/600) * 600;
		if($this_row[0] > 0)
		{
			my $log_change = 0;
			if($last_logged != -1)
				{$log_change = $this_row[0] - $last_logged};
			$Log_Size->{$this_time} += $log_change;
			$last_logged = $this_row[0];
			$Inserted->{$this_time} += $this_row[1];
			$ALog->{$this_time} += $log_change;
			$AInserted->{$this_time} += $this_row[1];
		}
	}
	return;
}

##########################
# Grabs the raw data for each system.
sub _get_aggregate_system_stat_data
{
	my	$results_ref,				# Used to hold query responses
		$row,						#Row array reference
		@this_row;					#Current row
	my $system, $Log_Size, $Inserted, $ALog, $AInserted, $Service;
	my $Time_Period;
	($system,$Log_Size,$Inserted, $ALog, $AInserted, $Service, $Time_Period)=@_ or die("Incorrect arguments to _get_system_stat_data.\n");

	$Time_Period = time()-$Time_Period;
	my $SQL = "SELECT Total_In_Log,Number_Inserted,Stat_Time,Service_Name FROM dad_sys_event_stats WHERE Service_Name='$Service' AND System_Name='$system' AND Stat_Time>$Time_Period ORDER BY Stat_Time";
	$results_ref = &SQL_Query($SQL);
	my $last_logged = -1;
	while($row = shift(@$results_ref) )
	{
		@this_row = @$row;
		my $this_time = $this_row[2];
		$this_time = int($this_time/600) * 600;
		if($this_row[0] > 0)
		{
			my $log_change = 0;
			if($last_logged != -1)
				{$log_change = $this_row[0] - $last_logged};
			$last_logged = $this_row[0];
			$ALog->{$this_time} += $log_change;
			$AInserted->{$this_time} += $this_row[1];
		}
	}
	return;
}



##################################################
#
# SQL_Query - Does the legwork for all SQL queries including basic error checking
# 	Takes a SQL string as an argument
#
##################################################
sub SQL_Query
{
	my $SQL = $_[0];

	my $query = $dbh->prepare($SQL);
	$query -> execute();
	my $ref_to_array_of_row_refs = $query->fetchall_arrayref();
	$query->finish();
	return $ref_to_array_of_row_refs;
}

##################################################
#
# SQL_Insert - Does the legwork for all SQL inserts including basic error checking
# 	Takes a SQL string as an argument
#
##################################################
sub SQL_Insert
{
	my $SQL = $_[0];
	my $query = $dbh->prepare($SQL);
	if($DEBUG){return; print"$SQL\n";return;}
	$query -> execute();
	$query->finish();
}
