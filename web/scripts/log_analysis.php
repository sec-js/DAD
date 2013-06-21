<?php
#   This file is a part of the DAD Log Aggregation and Analysis tool
#    Copyright (C) 2006, David Hoelzer/Cyber-Defense.org
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
// This is the log analysis builder. - 5/2006 - DSH

function show_existing_queries()
{
global $Global;
global $_GET;
    $strURL  = getOptionURL(OPTIONID_EXISTING_QUERIES);

if(isset($_GET["ContextQuery"]))
	{
		$word=$_GET["ContextQuery"];
		$Start = (isset($_GET["Start"]) ? ($_GET["Start"] > 0 ? $_GET["Start"] : 1) : 1);
		#		$strSQL=String_To_Query($word, 100000000, $Start, 50);
		$strSQL=GetQueryByStringsPosition("$word x", 10000000, $Start, 50);
		$Result_Contents = Query_to_Table($strSQL, 1, $Start);//, "PopupTable");
		//Popup("Test", $Popup_Contents, 980, 650, 5, 5);
		$strHTML = "<p><a href='$strURL&ContextQuery=$word&Start=".($Start-50)."'>< Previous</a> | ".
			"<a href='$strURL&ContextQuery=$word&Start=".($Start+50)."'> Next ></a><p><div class=results width=90% border=1 height=200px name=ResultSet>$Result_Contents</div>";
		add_element($strHTML);
		return;
	}



	$strSQL = 'SELECT Query_ID, Query, Name, Description, Category, Roles, Timeframe FROM dad_sys_queries ORDER BY Category,Name';
	$Queries = runQueryReturnArray( $strSQL );
	$strHTML = <<<END
		
		<table cellspacing=4 border=1>
END;
//PrintGlobal();
	$last_category = "None";
	$column=0;
	foreach($Queries as $Query)
	{
		$RolesWithAccess = null;  // Make sure there aren't any leftovers
		$RolesWithAccess = ExtractQueryRoles($Query["Roles"]);
		//if($RolesWithAccess[$Global[
		if($last_category != $Query["Category"])
		{
			$last_category = $Query["Category"];
			if($column != 0) { $strHTML .= "</tr>"; }
			$strHTML .= <<<END
			<tr colspan=5><th colspan=4>$last_category</th></tr><tr>
END;
			$column = 1;
		}
		$ID = $Query["Query_ID"];
		$Name = $Query["Name"];
		$Description = $Query["Description"];
		if($column % 5 == 0)
		{
			$strHTML .= "</tr>\n<tr>";
			$column = 1;
		}
		$strHTML .= <<<END
				<td><a href="$strURL&SubmittedQuery=$ID" title="$Description">$Name</a></td>
END;
	$column++;
	}
	$strHTML .= "</table></form>";
	add_element($strHTML);
	if(isset($_GET["SubmittedQuery"]))
	{
		$QueryID = $_GET["SubmittedQuery"];
		$Start = (isset($_GET["Start"]) ? ($_GET["Start"] > 0 ? $_GET["Start"] : 1) : 1);
		$strSQL = "SELECT Query, Name, Timeframe FROM dad_sys_queries WHERE Query_ID='$QueryID'";
		$strSQL = generateEventQuery($strSQL, $Start);
		$Result_Contents = Query_to_Table($strSQL, 1);//, "PopupTable");
		//Popup("Test", $Popup_Contents, 980, 650, 5, 5);
		$strHTML = "<p><a href='$strURL&SubmittedQuery=$QueryID&Start=".($Start-10)."'>< Previous</a> | ".
			"<a href='$strURL&SubmittedQuery=$QueryID&Start=".($Start+10)."'> Next ></a><p><div class=results width=90% border=1 height=200px name=ResultSet>$Result_Contents</div>";
		add_element($strHTML);
	}
}


function generateEventQuery($strSQL, $start=1, $limit=10)
{
   	if(!isset($strSQL)) { return ""; }
	$result = runQueryReturnArray($strSQL);
	if(!isset($result[0]['Query'])) { return ""; }
	$SearchTerms = $result[0]['Query'];
	$TimeFrame = $result[0]['Timeframe'];

	return(GetQueryByStringsPosition($SearchTerms, $TimeFrame, $start, $limit));
}

function GetQueryByStringsPosition($SearchTerms, $TimeFrame=86400, $start=1, $limit=10)
{
	$Array = split(" ",$SearchTerms);	
	add_element("$SearchTerms<br>");
	$num_terms = sizeof($Array);
	if(($num_terms % 2) != 0 )
	{
		return "SELECT 'Format for GetEventsByStringsPostion is TimeFrame(in seconds), String, Position[,String, Position...]'";
	}
	if($num_terms < 1)
	{
		return "SELECT 'No search terms present'";
	}
	$num_terms /= 2;
	#$TimeFrame = time()-$TimeFrame;
	$Report = "";
	$SearchTerms = "";
	$StringIDFilter="";
	#f "Searching for events that occurred since $TimeFrame with the terms:\n";
	for($i=0; $i!= $num_terms; $i++)
	{
		$t = strtolower($Array[$i * 2]);
		$p = $Array[($i * 2) + 1];
		# "$t - $p\n";
		$Terms[$i] = $t;
		if(ereg("[0-9]+", $p))
		{
			$Positions[$t] = $p;
		}	
		if(!$SearchTerms)
		{
			$SearchTerms = "'$t'";
		}
		else
		{
			$SearchTerms .= ",'$t'";
		}
	}

	# Events to find:	
	$SQL =<<<EndSQL
	SELECT * 
	FROM event_unique_strings 
	WHERE String IN ( $SearchTerms )
EndSQL;
	$string_ids = runQueryReturnArray($SQL);
	if(!isset($string_ids)) { return "SELECT 'No matching strings found'";}
	foreach($string_ids as $row)
	{
		if(!isset($Positions[strtolower($row[1])]))
		{ 
			if($StringIDFilter == "")
			{
				$table_ref = 'b';
				$StringIDFilter = " $table_ref.String_ID=$row[0]";
				$JOINS=" JOIN event_fields as $table_ref";
				$MATCHES=" AND a.Events_ID=$table_ref.Events_ID";
			}
			else # ORs should be in here somewhere attached to the $StringIDFilter
			{
				$table_ref++;
				$StringIDFilter .= " AND $table_ref.String_ID=$row[0]";
				$JOINS.=" JOIN event_fields as $table_ref";
				$MATCHES.=" AND a.Events_ID=$table_ref.Events_ID";
			}
		}
		else
		{ 
			if($StringIDFilter=="")
				{
					$table_ref = 'b';
					$StringIDFilter = "\n($table_ref.String_ID=$row[0] AND $table_ref.Position=".$Positions[strtolower($row[1])].")";
					$JOINS="\nJOIN event_fields as $table_ref";
					$MATCHES="\nAND a.Events_ID=$table_ref.Events_ID";
				}
				else
				{			
					$table_ref++;
					$StringIDFilter .= "\nAND ($table_ref.String_ID=$row[0] AND $table_ref.Position=".$Positions[strtolower($row[1])].")";
					$JOINS.="\nJOIN event_fields as $table_ref";
					$MATCHES.="\nAND a.Events_ID=$table_ref.Events_ID";
				}
		}

   	}
   	# The MySQL query optimizer does a bad job in deciding which index to use
   	# when we limit the query based on the time generated.  This code forces
   	# the queries that are time limited to use the idxTimeGenerated index rather
   	# than the Event_ID PRIMARY key.
   	$IDXFORCE="";
   	if($TimeFrame < 604800) # 1 week
   	{
   		$IDXFORCE="FORCE INDEX(idxTimeGenerated)";
   	}
   	$strSQL=<<<ENDSQL
		SELECT 
			DISTINCT a.Events_ID,
			a.Time_Written,
			a.Time_Generated 
		FROM 
			events as a $IDXFORCE $JOINS 
		WHERE 
			$StringIDFilter 
			$MATCHES
			AND a.Time_Generated > (UNIX_TIMESTAMP(NOW())-$TimeFrame)  
ENDSQL;
	#LIMIT $start,$limit 

   	add_element("Event search SQL: $strSQL <br><br>");
   	$Event_IDs = runQueryReturnArray($strSQL);
	if(!$Event_IDs)
	{
		return "SELECT 'No Events Matching Criteria Found.'";
	}
   	foreach($Event_IDs as $row)
   	{
   		if(!isset($Events_ID_in))
   		{
   			$Events_ID_in = "$row[0]";
   		}
   		else
   		{
   			$Events_ID_in .= ", $row[0]";
   		}
   	}
   	$strSQL=<<<ENDSQL
   				SELECT distinct
   					f.Events_ID as "Event Number",
   					FROM_UNIXTIME(e.Time_Generated) as "Time",
   					systems.System_Name as "System",
   					GROUP_CONCAT(s.String ORDER BY f.Position ASC separator ' ') as "Event Detail"
   				FROM
   					events as e,
   					event_fields as f,
   					event_unique_strings as s,
   					dad_sys_systems as systems
   				WHERE
   					e.Events_ID IN ( $Events_ID_in )
   					AND f.Events_ID=e.Events_ID
   					AND (
   						f.String_ID=s.String_ID
   						)
   					AND systems.System_ID=e.System_ID
   					GROUP BY f.Events_ID
   					ORDER BY e.Time_Generated,f.Events_ID,f.Position
ENDSQL;
   #add_element($strSQL);
   	return($strSQL);
}

function FulltextQuery($SearchTerms, $TimeFrame=86400, $start=1, $limit=10)
{
	$StringIDFilter = "";
	$Terms = "";
	$table_ref = "";
	$JOINS="";
	$MATCHES="";
	$Events_ID_in = "";
	$Terms = "";

	$SearchTerms = split(" ", $SearchTerms);
	foreach($SearchTerms as $value)
	{
		$Terms .= ($Terms == "" ? "'".$value."'" : ",'".$value."'");
	}
	$strSQL = "SELECT * FROM event_unique_strings WHERE String IN ( $Terms )";
	#add_element("$strSQL<br><br>");
	$string_ids = runQueryReturnArray($strSQL);
	foreach($string_ids as $row)
	{
		if($StringIDFilter == "")
		{
			$table_ref = 'b';
			$StringIDFilter = " $table_ref.String_ID=$row[0]";
			$JOINS=" JOIN event_fields as $table_ref";
			$MATCHES=" AND a.Events_ID=$table_ref.Events_ID";
		}
		else # ORs should be in here somewhere attached to the $StringIDFilter
		{
			$table_ref++;
			$StringIDFilter .= " AND $table_ref.String_ID=$row[0]";
			$JOINS.=" JOIN event_fields as $table_ref";
			$MATCHES.=" AND a.Events_ID=$table_ref.Events_ID";
		}
   	}
   	# The MySQL query optimizer does a bad job in deciding which index to use
   	# when we limit the query based on the time generated.  This code forces
   	# the queries that are time limited to use the idxTimeGenerated index rather
   	# than the Event_ID PRIMARY key.
   	$IDXFORCE="";
   	if($TimeFrame < 604800) # 1 week
   	{
   		$IDXFORCE="FORCE INDEX(idxTimeGenerated)";
   	}
   	$strSQL=<<<ENDSQL
		SELECT 
			DISTINCT a.Events_ID,
			a.Time_Written,
			a.Time_Generated 
		FROM 
			events as a $IDXFORCE $JOINS 
		WHERE 
			$StringIDFilter 
			$MATCHES
			AND a.Time_Generated > (UNIX_TIMESTAMP(NOW())-$TimeFrame)  
		LIMIT $start,$limit 
ENDSQL;
	#   	print "Event ID query: $strSQL <br><br>";
   	$Event_IDs = runQueryReturnArray($strSQL);
	if(!$Event_IDs)
	{
		return "SELECT 'No Events Matching Criteria Found.'";
	}
   	foreach($Event_IDs as $row)
   	{
   		if($Events_ID_in=="")
   		{
   			$Events_ID_in = "$row[0]";
   		}
   		else
   		{
   			$Events_ID_in .= ", $row[0]";
   		}
   	}
   	$strSQL=<<<ENDSQL
   				SELECT distinct
   					f.Events_ID as "Event Number",
   					FROM_UNIXTIME(e.Time_Generated) as "Time",
   					systems.System_Name as "System",
   					GROUP_CONCAT(s.String ORDER BY f.Position ASC separator ' ') as "Event Detail"
   				FROM
   					events as e,
   					event_fields as f,
   					event_unique_strings as s,
   					dad_sys_systems as systems
   				WHERE
   					e.Events_ID IN ( $Events_ID_in )
   					AND f.Events_ID=e.Events_ID
   					AND (
   						f.String_ID=s.String_ID
   						)
   					AND systems.System_ID=e.System_ID
   					GROUP BY f.Events_ID
   					ORDER BY e.Time_Generated,f.Events_ID,f.Position
ENDSQL;
   #add_element($strSQL);
   	return($strSQL);

// TODO - DSH, 12/2007
// The following code makes use of the stored procedures in the DAD database.  These are currently very inefficient for large datasets.
// Our current impression is that the problem is related to the poor optimization of sub queries within MySQL.
/*	$StringIDFilter = "";
	$Terms = "";
	$table_ref = "";
	$JOINS="";
	$MATCHES="";
	$Events_ID_in = "";
	
	if(!isset($strSQL)) { return ""; }
	$result = runQueryReturnArray($strSQL);
	if(!isset($result[0]['Query'])) { return ""; }
	return String_To_Query($result[0]['Query'], $result[0]['Timeframe'], $start, $limit);*/
}

// TODO - DSH, 12/2007
// This function is only used when the stored procedure code is active.
function String_To_Query($String, $TimeFrame=10000000, $start=1, $limit=10)
{
	$SearchTerms = split(" ",$String);
	switch(sizeof($SearchTerms))
	{
		case 0 : 
			return "SELECT 'No search terms!'";
			break;
		case 1 :
			return "CALL GetEventsBy1StringLimited('$SearchTerms[0]', $start, $limit, $TimeFrame)";
			break;
		case 2 :
			return "CALL GetEventsBy2StringsLimited('$SearchTerms[0]', '$SearchTerms[1]',$start, $limit, $TimeFrame)";
			break;
		case 3 :
			return "CALL GetEventsBy3StringsLimited('$SearchTerms[0]', '$SearchTerms[1]','$SearchTerms[2]',$start, $limit, $TimeFrame)";
			break;
		case 4 :
			return "CALL GetEventsBy4StringsLimited('$SearchTerms[0]', '$SearchTerms[1]','$SearchTerms[2]','$SearchTerms[3]',$start, $limit, $TimeFrame)";
			break;
		case 5 :
			return "CALL GetEventsBy5StringsLimited('$SearchTerms[0]', '$SearchTerms[1]','$SearchTerms[2]','$SearchTerms[3]','$SearchTerms[4]',$start, $limit, $TimeFrame)";
			break;
		case 6 :
			return "CALL GetEventsBy6StringsLimited('$SearchTerms[0]', '$SearchTerms[1]','$SearchTerms[2]','$SearchTerms[3]','$SearchTerms[4]','$SearchTerms[5]',$start, $limit, $TimeFrame)";
			break;
		default:
			return "SELECT 'Too many search terms.'";
			break;
	}
}

function show_log_stats() 
{

    global $gaLiterals;
	global	$Global;
	
//    $strSQL   = 'SELECT COUNT(*) FROM events';;
//    $events2 = runQueryReturnArray( $strSQL );
//	$num_events2 = $events2[0][0];
//    $strSQL   = 'SELECT COUNT(*) FROM event_fields';;
//    $fields = runQueryReturnArray( $strSQL );
//	$num_fields = $fields[0][0];
//    $strSQL   = 'SELECT COUNT(*) FROM event_unique_strings';;
//    $strings = runQueryReturnArray( $strSQL );
//	$num_strings = $strings[0][0];
    $strSQL   = 'SELECT System_Name FROM dad_sys_event_import_from';
    $systems = runQueryReturnArray( $strSQL );
    $num_systems = count($systems);
//    $strSQL   = 'SELECT COUNT(*) FROM dad_sys_services';
//    $num_services = runQueryReturnArray( $strSQL );
	$FreeSpace = disk_free_space(MYSQL_DRIVE);
	$TotalSpace = disk_total_space(MYSQL_DRIVE);
//	$MoreEvents = $FreeSpace/(($TotalSpace-$FreeSpace) / ($num_events2 + 1)+1);
	$PercentFree = round((($FreeSpace/($TotalSpace + 1)) * 100), 2);
	$PercentUsed = 100 - $PercentFree;
	$strHTML = "Disk Utilization: $PercentFree% Free<br>";
	$top_talkers = file("../TopTalkers.html");
	foreach($top_talkers as $line)
	{
		$strHTML .= $line;
	}

// Alerts
	if(isset($Global['AckMarker']) && $Global['AckMarker'] == '1')
	{
		acknowledge_events();
	}
	$strURL  = getOptionURL(OPTIONID_LOG_ANALYSIS);
	$strHTML .=<<<endHTML
		<!-- <iframe src='/stats/stats2.html' width=300px height=390px align=right></iframe> -->
		<form id='acknowledge_alerts' align=left action='$strURL' method='post'>
		<input type='hidden' name='AckMarker' value='1'></input>
		<p><h3>Pending Alerts</h3><input type='submit' value='Acknowledge Marked'></input></h3>
<div id="Scrollable" height=350px>
		<font size=-2>
		<table border='1' cellpadding='5'>
			<tr><th>Ack</th><th>Alert Time</th><th>Event Time</th><th>Alert</th></tr>
endHTML;
	$strSQL = "SELECT FROM_UNIXTIME(Alert_Time) as 'Alerted at', ".
		"FROM_UNIXTIME(Event_Time) as 'Event Timestamp', Event_Data as 'Alert', ".
		"Severity, dad_alert_id from dad_alerts WHERE Acknowledged=FALSE ORDER BY Severity DESC,Alert_Time DESC,Event_Time DESC";
	$alerts = runQueryReturnArray($strSQL);
	if(! $alerts)
	{
		$strHTML .= "<tr><td colspan='4'><center><H4>No Pending Alerts</h4></td></tr>";
	}
	else
	{
		foreach($alerts as $alert)
		{
			switch($alert[3])
			{
				case '0' : $bgcolor="#dddddd"; $fgcolor="#000000"; break; // Informational
				case '1' : $bgcolor="#10a010"; $fgcolor="#000000"; break; // Minor
				case '2' : $bgcolor="#c0c000"; $fgcolor="#000000"; break; //Important
				case '3' : $bgcolor="#ffff00"; $fgcolor="#000000"; break; //Medium
				case '4' : $bgcolor="#ff10aa"; $fgcolor="#000000"; break; //Serious
				case '5' : $bgcolor="#ff0000"; $fgcolor="#000000"; break; //Critical
			}
			$strHTML .=<<<endHTML
				<tr bgcolor='$bgcolor'><td><input name='ack_$alert[4]' type='checkbox' value='$alert[4]'></input></td><td>$alert[0]</td><td>$alert[1]</td><td>$alert[2]</td></tr>
endHTML;
		}
	}
	$strHTML .= "</table></font></div>";
// Stats
	$strHTML .= "<p><h3>Aggregate Log Statistics</h3><img src='/Stats/Aggregate.gif'>";
	if($systems) 
	{
		foreach($systems as $row)
		{
			$strHTML .= "<p><h3>".$row[0]." Log Statistics</h3><img src='/Stats/".$row[0].".gif'><hr>";
		}
	}
	else 
	{
		$strHTML .= "<p><h3>No systems are currently being monitored.</h3>";
	}
    add_element($strHTML);
}

/*
 *	acknowledge_events takes care of updating the dad_alerts table to mark events as handled.
 *	4/07 - DSH
 */
function acknowledge_events()
{
	global $Global;
	
	foreach($Global as $var => $value)
	{  
		if(substr($var, 0, 4) == "ack_")
		{
			$strSQL = "update dad_alerts set Acknowledged=TRUE, Acknowledged_by='".
				$Global['UserID']."', Acknowledged_Time=UNIX_TIMESTAMP(NOW()) WHERE dad_alert_id='".$value."'";
			runSQLReturnAffected($strSQL);
		}
	}
}

/*
 *	Query builder takes care of the dynamic interactive web interface for creating simple SQL
 *	queries.  Initially, some time was invested in creating an interface that would also support joins.
 *	This has been abandoned, but the initial code still exists, it simply needs to be re-enabled and
 *	completed.  The only piece remaining is to either programmatically determine where the tables
 *	should be joined, perhaps based on common field names, or to present the user with additional
 *	selection boxes to create the joins.
 *	5/06 - DSH
 */
function show_query_builder()
{
	global $Global;
	global $_POST;

    $strURL  = getOptionURL(OPTIONID_QUERY_BUILDER);

	# PrintMapping($_POST);
	# Retrieve the currently posted options for visible columns in the result set
	for($num_visible_fields = 0; isset($_POST["visible_fields_$num_visible_fields"]); $num_visible_fields++)
	{
		$visible_fields_selected[$num_visible_fields] = $HTTP_POST_VARS["visible_fields_$num_visible_fields"];
	}

	$num_visible_fields = ($num_visible_fields < 1 ? 0 : $num_visible_fields-=1);
	#Add one to the visible fields if we're actively adding a visible field with the last click
	if(isset($_POST["Operation"]) && $_POST["Operation"] == "Add Filter") { $num_visible_fields++; }

	#Retrieve the currently posted options for the filters in the result set
	for($num_filters = 0; isset($_POST["filter_value_$num_filters"]); $num_filters++)
	{ 
		#$filters_selected[$num_filters] = $_POST["filter_$num_filters"];
		$filter_types[$num_filters] = $_POST["filter_type_$num_filters"];
		$filter_values[$num_filters] = $_POST["filter_value_$num_filters"];
	}
	$num_filters = ($num_filters < 1 ? 0 : $num_filters-=1);
	if(isset($_POST["Operation"]) && $_POST["Operation"] == "Add Filter") { $num_filters++;}
	if(isset($_POST["Operation"]) && $_POST["Operation"] == "Remove Filter") { $num_filters--; }
	
# Set the javascript preamble for postbacks
	# The JavaScript below is intended to simulate the "Postback" functionality that ASP.NET has
	$strHTML = <<<END
		<script language="javascript">  
		<!--  
		function PostBack() 
		{
			var theform = document.QueryBuilder;  
			theform.submit();  
		}  
		// --> 
		</script>
END;


	
/* First we put out the primary table selector */
	$strHTML .= <<<END
	
		<font align=center size=+1>Please use the query builder below to create new searches</font>
		<form id=frmQueryBuilder name="QueryBuilder" action="$strURL" method="post" style="position:relative; top:25px;">
		<table cellpadding=5>
END;

/* Next we set the filter conditions */
	if(1) # If we have visible column fields then we must have at least one field selected even if it's only the default
	{
		$Options[0] = "Any";
		for($i=1; $i != 100; $i++)
		{
			$Options[$i] = "$i";
		}
		for($filter_num = 0; $filter_num != $num_filters+1; $filter_num++)
		{
			$FilterOptionList[$filter_num]="";
			$Filter_Type_List[$filter_num]="";
			for($i = 0; $i != 100; $i++)
			{
				$selected = ( 
					(isset($filter_types[$filter_num])?
						($filter_types[$filter_num] == $i ? " selected" : "")
						: "")); 
				$Filter_Type_List[$filter_num] .= "<option value=\"$i\"$selected>$Options[$i]";
			}
		}
		$query_name = (isset($_POST["query_name"]) ? $_POST["query_name"] : "");
		$query_popup = (isset($_POST["query_popup"]) ? $_POST["query_popup"] : "");
		$query_category = (isset($_POST["query_category"]) ? $_POST["query_category"] : "");
		$query_timeframe = (isset($_POST["timeframe"]) ? $_POST["timeframe"] : "0");
		switch($query_timeframe)
		{
			case 0 : $TimeFrame = 1000000000;
				break;
			case 1 : $TimeFrame = 2592000;
				break;
			case 2 : $TimeFrame = 604800;
				break;
			case 3 : $TimeFrame = 86400;
				break;
			case 4 : $TimeFrame = 3600;
				break;
			case 5 : $TimeFrame = 600;
				break;
			default : $TimeFrame = 1000000000;
		}
		if($query_timeframe == 0) { $established = "selected"; } else { $established = ""; }
		$timeframe_options = "<option value='0' $established>Any Time";
		if($query_timeframe == 1) { $established = "selected"; } else { $established = ""; }
		$timeframe_options .= "<option value='1' $established>30 Days";
		if($query_timeframe == 2) { $established = "selected"; } else { $established = ""; }
		$timeframe_options .= "<option value='2' $established>1 Week";
		if($query_timeframe == 3) { $established = "selected"; } else { $established = ""; }
		$timeframe_options .= "<option value='3' $established>24 Hours";
		if($query_timeframe == 4) { $established = "selected"; } else { $established = ""; }
		$timeframe_options .= "<option value='4' $established>1 Hour";
		if($query_timeframe == 5) { $established = "selected"; } else { $established = ""; }
		$timeframe_options .= "<option value='5' $established>10 Minutes";
		$Total_Strings = $filter_num;
		$strHTML .= <<<END
			<tr>
				<td>Query Short Name:</td><td><input type="text" name="query_name" value="$query_name" width=20></td>
			</tr>
			<tr>
				<td>Query Popup Description:</td><td><input type="text" name="query_popup" value="$query_popup" width=20></td>
			</tr>
			<tr>
				<td>Query Category:</td><td><input type="text" name="query_category" value="$query_category" width=20></td>
			</tr>
			<tr>
				<td>Query Timeframe Limit:</td><td><select name="timeframe">$timeframe_options</select></td>
			</tr>
				
				<td colspan=2 bgcolor="#ddddff"><center>Specify Strings and Positions for your result set</center></td>
			</tr>
				<th>String</th><th align=left>Position</th>
			</tr>
END;
		$goto = $num_filters;
		$strHTML .= "<tr><td colspan=2><table cellpadding=2>";
		for($i = 0; $i <= $num_filters; $i++)
		{
			$enabled = ($i == $num_filters ? "" : " disabled");
			$enabled2 = (($i == 0) || ($i != $num_filters) ? "disabled" : "");
			$local_filter_value = (isset($filter_values[$i])?$filter_values[$i]:"");
			$strHTML .= <<<END
			<tr>
				<td><input type="text" name="filter_value_$i" value="$local_filter_value" width=20></td>
				<td><select name="filter_type_$i">$Filter_Type_List[$i]</td>
				<td>
					<input name="Operation" type=submit value="Add Filter" $enabled>
					<input name="Operation" type="submit" OnClick="PostBack()" value="Remove Filter"$enabled2>
				</td>
			</tr>
END;
		}
		$strHTML .= "</table></td></tr>";
	
	}
/* And finally we choose grouping and sorting options */	

/* Last of all, we process a submitted query */
	$strHTML .= <<<END
		<tr bgcolor="#ddddff">
			<td colspan=2>
				<center>
					<input name="Operation" type=submit value="Process Result Set">
					<input type="submit" name="Operation" value="Save Query">
				</center>
			</td>
		</tr>
END;
	add_element($strHTML . "</table><hr>");
/* Build out the raw strings and positions in case we need to query or save. */
	for($i=0; $i!=$Total_Strings; $i++)
	{  
		$String = (isset($filter_values[$i])?$filter_values[$i]:'empty');
		$Position = (isset($filter_types)?($filter_types[$i] == 0 ? 'any' : $filter_types[$i]):'any');
		if(!isset($SearchString))
		{
			$SearchString = "$String $Position";
		}
		else
		{
			$SearchString .= " $String $Position";
		}

	}
/* Query string built */
	if(isset($_POST["Operation"]) and $_POST["Operation"] == "Process Result Set")
	{

		$SQL = GetQueryByStringsPosition($SearchString, $TimeFrame, 1, 50);
		add_element(query_to_table($SQL));
	}
	if(isset($_POST["Operation"]) and $_POST["Operation"] == "Save Query")
	{
		$strSQL = "INSERT INTO dad_sys_queries (Query, Description, Name, Category, Roles, Timeframe) ".
			"VALUES ('$SearchString', '$query_popup', '$query_name', '$query_category', '1,2', $TimeFrame)";
		$intRowsAffected = runSQLReturnAffected($strSQL);
		if ($intRowsAffected < 1)
		{
		$MYSQL_ERRNO = mysql_errno();
           $MYSQL_ERROR = mysql_error();
			add_element("<h4>Error inserting new saved query! $MYSQL_ERRNO : $MYSQL_ERROR</h4>");
		}
		else
		{
			add_element("<h4>Saved '$query_name'!</h4>");
		}
	}
}

/*
 *	The show_sql_query function handles the dynamic creation and execution of SQL queries through
 *	the web interface.  This function also provides a button to allow you to save a query for later use through
 *	the "Saved Queries" interface.
 *	5/06 - DSH
 */
function show_sql_query()
{
    global $Global;
	global $HTTP_POST_VARS;

    $strURL  = getOptionURL(OPTIONID_SQL_QUERY);   
    // Get post variables from $Globals array
    $strSQLQuery = isset($Global["txtSQLQuery"]) ? $Global["txtSQLQuery"] : NULL;
    $txtQueryName = isset($Global["txtQueryName"]) ? $Global["txtQueryName"] : NULL;
    $txtQueryDescription = isset($Global["txtQueryDescription"]) ? $Global["txtQueryDescription"] : NULL;
    $txtQueryCategory = isset($Global["txtQueryCategory"]) ? $Global["txtQueryCategory"] : NULL;
	$intSelectedQuery = isset($Global["SelectedQuery"]) ? $Global["SelectedQuery"] : NULL;
	$txtPostbackValue = isset($Global["PostbackValue"]) ? $Global["PostbackValue"] : NULL;
	$aResults = runQueryReturnArray("SELECT Query_ID,Name,Description,Category,Query,Roles FROM dad_sys_queries ORDER BY Category,Name");
	$ExistingQueriesOptions = "<option value=''>";
	$txtRolesWithAccess=""; # Defaults to no roles.
	foreach($aResults as $row)
	{
		$selected = "";
		if(isset($intSelectedQuery))
		{
			if($intSelectedQuery == $row[0])
			{
				$selected = " selected";
				if($txtPostbackValue == "QueryChange")
				{
					$strSQLQuery = $row[4];
					$txtQueryName = $row[1];
					$txtQueryDescription = $row[2];
					$txtQueryCategory = $row[3];
					$txtRolesWithAccess = $row[5];
				}
			}
		}
		$tmpString = "$row[3]: $row[1] - $row[2]";
		$tmpString = substr($tmpString, 0, 100);
		$ExistingQueriesOptions .= "<option value=\"$row[0]\"$selected>$tmpString";

	}
	if(isset($Global["btnSave"]))
	{
		if($txtQueryName == "" or $txtQueryDescription == "" or $txtQueryCategory == "" or $strSQLQuery == "") 
		{
			add_element("<h4>You must include a name, description, category and query.</h4>");
		}
		else
		{
			$strSQL = "INSERT INTO dad_sys_queries (Query, Description, Name, Category) ".
				"VALUES ('$strSQLQuery', '$txtQueryDescription', '$txtQueryName', '$txtQueryCategory')";
			$intRowsAffected = runSQLReturnAffected($strSQL);
			if ($intRowsAffected < 1)
			{
			$MYSQL_ERRNO = mysql_errno();
            $MYSQL_ERROR = mysql_error();
				add_element("<h4>Error inserting new saved query! $MYSQL_ERRNO : $MYSQL_ERROR</h4>");
			}
			else
			{
				add_element("<h4>Saved '$ButtonName'!</h4>");
			}
		}
	}
	$strSQLQuery = stripslashes($strSQLQuery);
	$output = (isset($Global["btnProcess"]) ? 1 : 0);
	$Roles = GetRoleTable(ExtractQueryRoles($txtRolesWithAccess));
	if(!$strSQLQuery) { $strSQLQuery = "Enter your query here"; }
# Set the javascript preamble for postbacks
# The JavaScript below is intended to simulate the "Postback" functionality that ASP.NET has
	$strHTML = <<<END
		<script language="javascript">  
		<!--  
		function PostBack(postbacktype) 
		{
			var theform = document.QueryMaintenance;  
			theform.PostbackValue.value=postbacktype;
			theform.submit();  
		}  
		// --> 
		</script>

		<form id="frmSQLQuery" name="QueryMaintenance" action="$strURL" method="post" style="position:relative; top:25px;">
			<input type=hidden name="PostbackValue" value="">
			<table cellpadding=5>
			<tr>
				<td colspan="2"><h2>SQL Query Maintenance</h2></td>
			</tr>
			<tr>
				<td colspan='2'>Existing Queries: <select OnChange="PostBack('QueryChange')" name=SelectedQuery>$ExistingQueriesOptions</select></td>
			</tr>
			<tr>
				<td valign=top>Please enter query here:</td>
				<td><textarea id="txtSQLQuery" cols="60" rows="8" name="txtSQLQuery">$strSQLQuery</textarea></td>
			</tr>
			<tr>
				<td>Query Name: <input type=text size=15 name="txtQueryName" value="$txtQueryName"><br>
					Category: <input type=text size=15 name="txtQueryCategory" value="$txtQueryCategory"></td>
				<td><textarea name="txtQueryDescription" rows=4 cols=60>$txtQueryDescription</textarea>
			</tr>
			<tr>
				<td colspan=2>
					<center>
						<input type="submit" name="btnProcess" value="Process This Query">
						<input type="submit" name="btnSave" value="Save as New Query">
						<input type="submit" name="btnUpdate" value="Save Modified Query">
					</center>
				</td>
			</tr>
			<tr><td colspan=2>$Roles</td></tr>
			</table>               
		</form><br>
END;
	$strHTML .= "<hr>";
	if($output) 
	{ 
		$strHTML .= Query_to_Table(FulltextQuery($strSQLQuery));
	}
	add_element($strHTML);
}

function ExtractQueryRoles($QueryRoleString)
{
	if(isset($QueryRoleString))
	{
		$RoleArray = split(",", $QueryRoleString);
		foreach ($RoleArray as $RoleID)
		{
			$RolesWithAccess[$RoleID] = 1;
		}
	}
	else
	{
		return null;
	}
	return $RolesWithAccess;
}

function GetRoleTable($RolesWithAccess)
{
  $sql = "SELECT
            ro.RoleID,
            ro.RoleName,
            ro.RoleDescr
          FROM dad.Role ro";
  $RoleResults = runQueryReturnArray($sql);
  $RoleTable = "";
  foreach($RoleResults as $row)
  {
    $Checked = "";
    $RoleName = $row['RoleName'];
    $RoleID = $row['RoleID'];
    $RoleDesc = $row['RoleDescr'];
    if(isset($RolesWithAccess[$RoleID]))
    {
      $Checked = "checked";
    }
    $RoleTable .= "<input type=\"checkbox\" $Checked name=\"$RoleName\" value=\"$RoleID\">$RoleDesc</input><br>";
  }
  return($RoleTable);
}
/*
  query_to_table(SQL Expression[, Quiet[, TableClass]])
  
	This function processes a SQL expression and returns the result set as an HTML table with column headings
*/
function query_to_table($QueryString, $quiet=0, $TableClass="default", $Start=1)
{
global $Global;

	$retval = "";
	$aResults = runQueryReturnArray($QueryString);
	if(! $quiet){ $retval .="<h3>Query results for:</h3>$QueryString<p>"; }
	$retval .= build_table_from_query($aResults,"*","2","1","1","#dddddd","#ffaaaa", $TableClass, $Start);
	return($retval);		
}

function another_func()
{
global $HTTP_GET_VARS;

$start = (isset($HTTP_GET_VARS["start"]) ? $HTTP_GET_VARS["start"] : 0);

$result_id = mysql_query("SELECT DISTINCT dad_sys_systems.System_Name as SystemName, dad_sys_events.Timestamp as Timestamp, ".
	"dad_sys_services.Service_Name as ServiceName, dad_sys_events.Field_4 as DomainUser, dad_sys_events.Field_6 as Message ".
	"FROM dad_sys_systems, dad_sys_events, dad_sys_services ".
	"WHERE ".
		"dad_sys_services.Service_ID = dad_sys_events.Service_ID and ".
		"dad_sys_systems.System_ID = dad_sys_events.System_ID and ".
		"dad_sys_events.Service_ID=1994 and ".
		"dad_sys_events.Field_6 NOT LIKE 'Login%' and ".
		"dad_sys_events.Field_6 NOT LIKE 'ERROR: #1024%' and ".
		"dad_sys_events.Field_6 NOT LIKE 'Failed login%'");
$i = 0;
$row_count = mysql_num_rows($result_id);
if($start < 0 or $start > $row_count)
{
	print "<h3>Illegal starting point:  $start</h3>";
	mysql_free_result($result_id);
	exit;
}
if($start > 0) 
{ 
	if($start >= 500) { $prev_start = $start - 500; } else { $prev_start = 0; }
	$next = "<a href='?start=$prev_start'>Previous</a> ";
}
else { $next = "Previous "; }
if($start < $row_count)
{
	if($row_count - $start > 500) 
	{
		$next_start = $start + 500;
		$next .= "<a href='?start=$next_start'>Next</a><hr>";
	}
	else { $next .= "Next<hr>"; }
}
else { $next .= "Next<hr>"; $start=$row_count - 500; }

?>
<?=$next?>
<h3>Rows <?=$start?> to <?=($start+500 > $row_count ? $row_count : $start+500)?> out of <?=$row_count?> rows</h3>
<table border=1 cellpadding=3 cellspacing=3>
	<tr><td>System</td><td>Time</td><td>Service</td><td>Domain/User</td><td>Event</td></tr>
	<?php

	while($i++ < $start and $row = mysql_fetch_row($result_id)){;}
	while($i++ < $start+500 and $row = mysql_fetch_row($result_id))
	{
	
?>
		<tr><td><?=$row[0]?></td>
		<td><?=$row[1]?></td>
		<td><?=$row[2]?></td>
		<td><?=$row[3]?></td>
		<td><?=$row[4]?></td></tr>
<?php		
		
	}
	mysql_free_result($result_id);

}
?>