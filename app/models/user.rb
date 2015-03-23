class User < ActiveRecord::Base
  has_and_belongs_to_many :roles
  has_many :rights, through: :roles
  has_one :session
  
  def zero_attempts
    self.attempts = 0
  end
  
  def self.hidden?(current_user = nil)
    return true if current_user.nil?
    return true unless current_user.has_right?("Admin")
    return false
  end
  
  def ungranted_roles
    return Role.all - self.roles
  end
  
  def has_right?(right)
    right = Right.where(:name => right).first
    admin = Right.where(:name => "Admin").first
    return true if self.rights.include?(admin) # Always return true for the administrator role regardless of rights
    self.rights.include?(right)
  end

  def has_role?(role)
    self.roles.include?(role)
  end
end