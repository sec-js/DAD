class Event < ActiveRecord::Base
  has_and_belongs_to_many :words
  has_and_belongs_to_many :alerts
  belongs_to :system
  belongs_to :service

  @bulk_insert_size=((Rails.env.development? || Rails.env.test?) ? 1 : 2000)
  @@cached_words = Hash.new
  @added = 0
  @cache_hits = 0
  @@num_cached = 0
  CACHESIZE=8000
  @@cachelifetime=15
  HUNKSIZE=16
  
  @inserted_last_run = 100
  @@nextEventID = -1
  @@pendingEventValues = Set.new
  @@system_cache = Hash.new
  @@service_cache = Hash.new
  @@hunk_cache = Hash.new
  @@events_words = Set.new
  @@start_time = Time.now
  @display_helper = nil       # Using lazy initialization but still using instance vars so that we
  @event_fields = nil         # instantiate lazily but still only do one SQL query per event
  @@current_year = Time.new.year
  
  def self.hidden?(current_user = nil)
    return true if current_user.nil?
    return true unless current_user.has_right?("Viewer")
    return false
  end
  
  def display_helper
    @display_helper = @display_helper.nil? ? Display.helper_for_event(self.inspect) : @display_helper
    @display_helper
  end
  
  def use_display_helper?
    return (self.display_helper.nil? ? false : true)
  end
  
  def parsed
    @display_helper = self.display_helper
    return "BAD DISPLAY FILTER" if @display_helper.nil?
    eval @display_helper.display_script
    parse_event(self.inspect)
  end
  
  def event_fields
    return @event_fields unless @event_fields.nil?
    @event_fields = Array.new
    self.hunks.split(/,/).each do |hunk|
      @event_fields << Hunk.find(hunk).text
    end
    @event_fields
  end
  
  def reconstitute
    string = ""
		self.hunks.split(/,/).each do |hunk|
      string = string + "#{Hunk.find(hunk).text}"
    end
    return string
  end
  
  def inspect
    string = "#{self.system.display_name}|#{self.generated}|"
    self.hunks.split(/,/).each do |hunk|
      string = string + "#{Hunk.find(hunk).text}"
    end
    return string
  end

  def self.diskUtilization
    `df -m | egrep "\s+/$"`.split(/\s+/)[4].to_i
  end
  
  def self.resetStats
    @@start_time = Time.now
  end
  
  def self.search(search_string, starting_time=(Time.now - 1.hour), offset=0, limit=100)
    @events = Array.new
    event_ids = Array.new
    connection = ActiveRecord::Base.connection
    
    terms = search_string.downcase.split(/\s+/)
    return [] if terms.empty?
    sql = "select event_id from events_words where generated > '#{starting_time.to_s(:db)}' and word_id in (select distinct id from words where words.text in ('#{terms.join("', '")}')) group by events_words.event_id having count(distinct(word_id))=#{terms.count}"
    puts sql
    events_that_match = connection.execute(sql)
    events_that_match.map { |e| event_ids << e["event_id"]}
    @events = Event.order(generated: :asc).includes(:words).where("id in (?)", event_ids).limit(limit).offset(offset)
    return (@events.nil? ? [] : @events)    
  end 
  
  def self.iterativeSQLBuilder(sortedWordIDs, depth, starting_time)
    # this is massively bad in so many ways.
    sql = ""
    sql = "select distinct e.event_id from (" if depth == 0
    word_id = sortedWordIDs.pop
    sql = sql + "select distinct a#{depth}.event_id from events_words as a#{depth} where a#{depth}.word_id=#{word_id}"+(sortedWordIDs.count > 0 ? " and a#{depth}.event_id in (#{iterativeSQLBuilder(sortedWordIDs, depth+1, starting_time)})" : " and a#{depth}.generated>'#{starting_time.to_s(:db)}'")
    sql = sql + ") as e" if depth == 0
    return sql
  end


  
  def self.search2(search_string, starting_time=(Time.now - 1.hour), offset=0, limit=100)
    @events = Array.new
    event_ids = Array.new
    connection = ActiveRecord::Base.connection
    ordered_words = Hash.new

    search_string.downcase!
    terms = search_string.split(/\s+/)
    words = Word.where("text in (?)", terms).order("words.id DESC").pluck(:id)
    return [] if words.empty?

#    words.each do |word_id|
#      sql = "select count(*) from events_words where word_id=#{word_id} and generated>NOW()-'1 day'::interval"
#      results = connection.execute(sql)
#      count = results[0]["count"]
#      puts "#{word_id} was found #{count} times"
#      ordered_words[word_id] = count
#      return [] if(count == 0)
#    end

# Let's try assuming that words added later likely appear less often.
#    sql = iterativeSQLBuilder(ordered_words.sort_by{|k,v| v}, 0)
    sql = iterativeSQLBuilder(words,0, starting_time)
    #sql = "select e.event_id from (select distinct a.event_id,a.word_id from events_words as a where a.generated>NOW()-'1 day'::interval and a.word_id in (#{word}) #{event_ids.empty? ? "" : "and a.event_id in (#{event_ids.join(',')})"} group by event_id,word_id) as e"
    puts sql


    #sql = "select e.event_id from (select distinct a.event_id,a.word_id from events_words as a where a.word_id in (#{words.join(",")}) group by event_id,word_id having count(distinct(a.event_id,a.word_id))=#{words.count}) as e"

    # Removed time contraint: a.generated>NOW()-'1 day'::interval and
    # Still can't get the count to work properly even with distinct in the count.  Absolutely crazy.
    # Will add back in word stat logic and rebuild the massive sub selects even though it feels really wrong.
    #  select distinct e.event_id from (select distinct a.event_id from events_words as a where a.event_id in (select distinct b.event_id from events_words as b where b.word_id=8352832 and b.generated>NOW()-'1 day'::interval) and a.word_id=8338947) as e;
    events_that_match = connection.execute(sql)
    events_that_match.map { |e| event_ids << e[0]}
    @events = Event.order(generated: :asc).includes(:words).where("id in (?)", event_ids).limit(limit).offset(offset)
    return (@events.nil? ? [] : @events)
  end    

  def self.storeEvent(eventString)
    # This next line is to seek and destroy invalid UTF-8 byte sequences.  They seem to show up in some
    # logs sometimes in URLs.
    service_offset = 5
    eventString = eventString.encode('UTF-8', :invalid => :replace)
    eventString.tr!("\r\n", "")
    hunks = eventString.scan(/.{1,HUNKSIZE}/)
    eventString.downcase!
    eventString.gsub!(/([^a-zA-Z0-9 \-_:@\*\/.])/," " )
    split_text = eventString.split(/\s+/)
    if split_text.count < 5 then
      puts "Invalid for syslog format: Too few fields -> #{eventString}"
      return
    end
    return unless split_text.size > 1 # If there's no date and only an IP then it's not a valid message.
    if split_text[1] =~ /20[0-9][0-9]-[0-1][0-9]-[0-3][0-9]t[0-9][0-9]:[0-9][0-9]:[0-9][0-9]\.[0-9][0-9][0-9]z/ then
      timestamp = DateTime.parse("#{split_text[1]}")
      service_offset = 3
    elsif split_text[3].to_i > 2014 && split_text[3].to_i < 2020 then
      txttimestamp = split_text[1..4].join(' ')
      begin
        timestamp = DateTime.parse("#{txttimestamp} GMT")
      rescue Exception => e
        puts "#{e}: #{eventString}"
        return
      end
    else
      txttimestamp = split_text[1..3].join(' ')
      begin
        timestamp = DateTime.parse("#{txttimestamp} #{@@current_year} GMT")
      rescue Exception => e
        puts "#{e}: #{eventString}"
        return
      end
    end
    txtsystem = split_text[0]
    if @@system_cache.has_key?(txtsystem) then
      system = @@system_cache[txtsystem]
    else
      system = System.find_or_add(txtsystem)
      @@system_cache[txtsystem] = system
    end
    txtservice = split_text[service_offset]
    if txtservice.nil? then
      puts "Service empty: #{eventString}"
      return
    end
    txtservice = txtservice.tr("^a-zA-Z\-_/","")
    if @@service_cache.has_key?(txtservice) then
      service = @@service_cache[txtservice]
    else
      service = Service.find_or_add(txtservice)
      @@service_cache[txtservice] = service
    end
    if @@nextEventID == -1 then
      if Event.all.count == 0 then
        @@nextEventID = 1
      else
        @@nextEventID = Event.order(id: :desc).limit(1)[0].id + 1 if @@nextEventID == -1
      end
    end

    split_text = split_text.to_set.to_a
    split_text.each do |word| 
      if @@cached_words.has_key?(word) then
        @@cached_words[word][:last] = Time.now
        @cache_hits += 1
        dbWord = @@cached_words[word][:id]
      else
        dbWord = Word.find_or_add(word)
        @@num_cached += 1
        @@cached_words[word] = {:id => dbWord, :last => Time.now}
      end
      @@events_words.add "(#{@@nextEventID}, #{dbWord}, '#{timestamp.to_s(:db)}')"
    end

    hunk_string = hunks.shift
    if @@hunk_cache.has_key?(hunk_string) then
      hunk = @@hunk_cache[hunk_string][:id]
    else
      newhunk = Hunk.where(:text => hunk_string).first
      if newhunk.nil? then
        newhunk = Hunk.new()
        newhunk.text = hunk_string
        newhunk.save
      end
      @@hunk_cache[hunk_string] = {:id => newhunk.id, :last => Time.now}
      hunk = @@hunk_cache[hunk_string][:id]
    end
    hunkString = "#{hunk}"
    hunks.each do |hunk_string|
      if @@hunk_cache.has_key?(hunk_string) then
        hunk = @@hunk_cache[hunk_string][:id]
      else
        newhunk = Hunk.where(:text => hunk_string).first
        if newhunk.nil? then
          newhunk = Hunk.new()
          newhunk.text = hunk_string
          newhunk.save
        end
        @@hunk_cache[hunk_string]= {:id => newhunk.id, :last => Time.now}
        hunk = @@hunk_cache[hunk_string][:id]
      end
      hunkString = "#{hunkString},#{hunk}"
    end
    @@pendingEventValues.add "(#{@@nextEventID}, #{system.id}, #{service.id}, '#{timestamp.to_s(:db)}', '#{Time.now.to_s(:db)}', '#{hunkString}')"
    #    event = Event.create(:system_id => system.id, :service_id => service.id, :generated => timestamp, :stored => Time.now)
    #    return nil if event.nil?
    @@nextEventID += 1
    self.performPendingInserts if @@pendingEventValues.count >= @bulk_insert_size
  end

  def self.performPendingInserts
    return if @@pendingEventValues.count < 1
    connection = ActiveRecord::Base.connection

    #puts "Current event_id: #{@@nextEventID} - last: #{@@pendingEventValues[0]}" # Can't do this with a set
    events_words_sql = "INSERT INTO events_words (event_id, word_id, generated) VALUES #{@@events_words.to_a.join(", ")}"
    connection.execute events_words_sql
    # Let's insert the words first so that we don't have to do it again.
    
    event_sql = "INSERT INTO events (id, system_id, service_id, generated, stored, hunks) VALUES #{@@pendingEventValues.to_a.join(", ")}"
    connection.execute event_sql

    puts "\t\t-->> Flushed #{@@pendingEventValues.count} events. <<--"
    elapsed_time = (Time.now - @@start_time)
    eventsPerSecond = @@pendingEventValues.count/elapsed_time
    puts "\t\t-->> Started run: #{@@start_time}\t#{elapsed_time} seconds elapsed\t#{eventsPerSecond} events processed per second."
    puts "\t\t-->> First word cache: #{@@cached_words.keys.count}"
    puts "\t\t-->> Hunk cache: #{@@hunk_cache.keys.count}"
    if @inserted_last_run > eventsPerSecond then
      @bulk_insert_size = (@bulk_insert_size > 20 ? @bulk_insert_size - 20 : 20)
    else
      @bulk_insert_size += 20
    end
    @inserted_last_run = eventsPerSecond
    Statistic.logEventsPerSecond(eventsPerSecond)
    @@start_time = Time.now
    @@pendingEventValues = Set.new
    @@events_words = Set.new
    @@current_year = Time.new.year
    self.prune_hunk_cache if @@hunk_cache.keys.count > CACHESIZE
    self.prune_words if @@num_cached > CACHESIZE
  end
  
  def self.prune_hunk_cache
    current_time = Time.now
    prune_time = current_time - @@cachelifetime
    @@hunk_cache = @@hunk_cache.select{|k,v| v[:last] > prune_time }
    puts "\t>>> Pruned hunk cache: #{CACHESIZE - @@hunk_cache.keys.count}"
  end
  
  def self.prune_words
    current_time = Time.now
    prune_time = current_time - @@cachelifetime
    @@cached_words = @@cached_words.select{|k,v| v[:last] > prune_time }
    pruned_count = CACHESIZE - @@cached_words.keys.count
    puts "\t+++ Pruned approximately #{pruned_count}."
    if pruned_count > (CACHESIZE / 3) then
      @@cachelifetime += 1
      puts "\t+++ Cache lifetime increased to #{@@cachelifetime}."
    else 
      @@cachelifetime -= 1
      puts "\t+++ Cache lifetime decreased to #{@@cachelifetime}."
    end
    puts "\t+++ There have been #{@cache_hits} hits in the word cache."
    @@num_cached = @@cached_words.keys.count
  end
  
end
