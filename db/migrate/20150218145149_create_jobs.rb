class CreateJobs < ActiveRecord::Migration
  def change
    create_table :jobs do |t|
      t.string :name
      t.text :description
      t.integer :user_id
      t.text :script
      t.integer :last_event_id
      t.timestamp :last_run
      t.timestamp :next_run
      t.integer :frequency

      t.timestamps
    end
  end
end