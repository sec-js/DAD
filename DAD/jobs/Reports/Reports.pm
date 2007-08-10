#   Report and Alert Library
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
#################################################
#
# Functions:
#
# SendEmail( $eSub, $eBody, $eTo, $eFrom, $eCC, $eBCC )
#	Allows you to send an email to an arbitrary desination and with an arbitrary source.
#	You must properly configure the mail server value for this to function.
#
#  string GetEventsByStrings($TimeFrameInSeconds, $string1[, $string2[, ...]])
#	Allows you to quickly query the events database for all of the events in the given
#	time range (in seconds) that contain all of the specified words.  You must specify
#	at least one search term.  There may be future changes to this function to limit
#	the number of values returned.


# Modules for DB and Event logs.  POSIX is required for Unix time stamps
use DBI;
use POSIX;
#Read in and evaluate the configuration values
open(FILE,"../dbconfig.ph") or die "Could not find configuration file!\n";
foreach (<FILE>) { eval(); }
close(FILE);

sub GetEventsByStrings
{
	my $TimeFrame = shift(@_);
	my $num_terms = @_;
	my $SearchTerms = "";
	
	if($num_terms < 1)
	{
		return "No search terms present\n";
	}
	$TimeFrame = time()-$TimeFrame;
	$dsn = "DBI:mysql:host=$MYSQL_SERVER;database=DAD";
	$dbh = DBI->connect ($dsn, "$MYSQL_USER", "$MYSQL_PASSWORD")
		or return "Could not connect to DB server to run alerting.\n";
	$Report = "";
	
	foreach(@_)
	{
		if($SearchTerms eq "")
		{
			$SearchTerms = "'$_'";
		}
		else
		{
			$SearchTerms .= ", '$_'";
		}
	}
	# Events to find:	
	$SQL = q{
	SELECT * 
	FROM event_unique_strings 
	WHERE String IN ( }. $SearchTerms .q{ )
	};#print "$SQL\n";exit;
	$results_ref = &SQL_Query($SQL);
	$num_results = @$results_ref;
	if($num_results < $num_terms)
	{
		print "Could only find $num_results out of $num_terms terms.  Search cancelled.\n";
		exit 1;
	}
	$StringIDFilter = "";
	if($num_results)
	{
		while($row = shift(@$results_ref))
		{
			@this_row = @$row;
			
			if($StringIDFilter eq "")
			{
				$table_ref = 'c';
				$StringIDFilter = "\n$table_ref.String_ID=$this_row[0]";
				$JOINS="\nJOIN event_fields as $table_ref";
				$MATCHES="\nAND b.Events_ID=$table_ref.Events_ID";
			}
			else
			{
				$table_ref++;
				$StringIDFilter .= "\nAND $table_ref.String_ID=$this_row[0]";
				$JOINS.="\nJOIN event_fields as $table_ref";
				$MATCHES.="\nAND b.Events_ID=$table_ref.Events_ID";
			}
		}
		$SQL=q{
			SELECT DISTINCT a.Events_ID,a.Time_Written,a.Time_Generated
			FROM events as a, event_fields as b
			}. $JOINS .q{
			WHERE }. $StringIDFilter .q{ AND a.Events_ID=b.Events_ID }. $MATCHES .q{
			LIMIT 100
			};#print "$SQL\n"; exit 1;
		my $results_ref2 = &SQL_Query($SQL);
		$num_results = @$results_ref2;
		if($num_results)
		{
			my $Events_ID_in = "";
			while($trow = shift(@$results_ref2))
			{
				my @mrow = @$trow;
				if($Events_ID_in eq "")
				{
					$Events_ID_in = "$mrow[0]";
				}
				else
				{
					$Events_ID_in .= ", $mrow[0]";
				}
			}	
			$SQL = q{
				SELECT 
					f.Events_ID, 
					FROM_UNIXTIME(e.Time_Generated),
					systems.System_Name, 
					GROUP_CONCAT(s.String ORDER BY f.Position ASC separator ' ')
				FROM
					events as e,
					event_fields as f,
					event_unique_strings as s, 
					dad_sys_systems as systems
				WHERE
					e.Events_ID IN (}. $Events_ID_in .q{)
					AND f.Events_ID=e.Events_ID
					AND (
						f.String_ID=s.String_ID
						)
					AND systems.System_ID=e.System_ID
					AND e.Time_Generated > }. $TimeFrame .q{
					GROUP BY f.Events_ID
					ORDER BY f.Events_ID,f.Position	, e.Time_Generated			
				};#print "$SQL\n";exit 1;
			my $event_detail_ref = &SQL_Query($SQL);
			$num_results = @$event_detail_ref;
			if($num_results)
			{
				my $edrs;
				while($edrs=shift(@$event_detail_ref))
				{
					my @edra=@$edrs;
					$Report .= "$edra[1] $edra[2] $edra[3]\n";
				}
			}
		}
	}
	return "$Report\n";

}

####################
## send_email( $eSub, $eBody, $eTo, $eFrom, $eCC, $eBCC );
##
    sub SendEmail{
        use Net::SMTP;
        my( $eSub, $eBody, $eTo, $eFrom, $eCC, $eBCC ) = @_;

        $eFrom = $ENV{USER} if $eFrom eq '';
        $eFrom = $ENV{USERNAME} if $eFrom eq '';
        $eTo   = 'postmaster' if $eTo eq '';

        my $smtp = Net::SMTP->new('mail');#, Debug => 1);
        $smtp->mail(    $eFrom );
		my @recipients = split (/[;,]/,$eTo);
		$smtp->recipient(@recipients, { SkipBad => 1});
        foreach( split/[;,]/,$eCC ){
            $smtp->cc($_);
        }
        foreach( split/[;,]/,$eBCC ){
            $smtp->bcc($_);
        }

        $smtp->data();
        $smtp->datasend("To: $eTo\n");          ## displayed TO names
        $smtp->datasend("Subject: $eSub\n");    
        $smtp->datasend("\n");               # have to have a blank line in between the subject and the body.
        foreach( split/\n/,$eBody ){
            $smtp->datasend("$_\n");
        }
        #$smtp->datasend("$eBody");
        $smtp->dataend();

        $smtp->quit;
        return 1;
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
	sub SQL_Insert
	{
		my $SQL = $_[0];
#		my $query = $dbh->prepare($SQL);
		if($DEBUG){return; print"$SQL\n";return;}
#		$query -> execute();
#		$query->finish();

		my $result;
		$dbh->do($SQL) or die;
		$result = $dbh->{ q{mysql_insertid}};
		return $result;

	}