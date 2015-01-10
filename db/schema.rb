# encoding: UTF-8
# This file is auto-generated from the current state of the database. Instead
# of editing this file, please use the migrations feature of Active Record to
# incrementally modify your database, and then regenerate this schema definition.
#
# Note that this schema.rb definition is the authoritative source for your
# database schema. If you need to create the application database on another
# system, you should be using db:schema:load, not running all the migrations
# from scratch. The latter is a flawed and unsustainable approach (the more migrations
# you'll amass, the slower it'll run and the greater likelihood for issues).
#
# It's strongly recommended that you check this file into your version control system.

ActiveRecord::Schema.define(version: 20150110190818) do

  create_table "events", force: true do |t|
    t.integer  "system_id"
    t.integer  "service_id"
    t.datetime "generated"
    t.datetime "stored"
    t.datetime "created_at"
    t.datetime "updated_at"
  end

  add_index "events", ["generated"], name: "index_events_on_generated"

  create_table "positions", force: true do |t|
    t.integer  "word_id"
    t.integer  "position"
    t.integer  "event_id"
    t.datetime "created_at"
    t.datetime "updated_at"
  end

  add_index "positions", ["event_id"], name: "index_positions_on_event_id"
  add_index "positions", ["word_id"], name: "index_positions_on_word_id"

  create_table "services", force: true do |t|
    t.string   "name"
    t.text     "description"
    t.datetime "created_at"
    t.datetime "updated_at"
  end

  create_table "systems", force: true do |t|
    t.string   "address"
    t.string   "name"
    t.text     "description"
    t.string   "administrator"
    t.string   "contact_email"
    t.datetime "created_at"
    t.datetime "updated_at"
  end

  create_table "words", force: true do |t|
    t.string   "text"
    t.datetime "created_at"
    t.datetime "updated_at"
  end

  add_index "words", ["text"], name: "index_words_on_text"

end
