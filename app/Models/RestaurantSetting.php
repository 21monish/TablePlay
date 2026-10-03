<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RestaurantSetting extends Model {
 protected $guarded=[];
 protected $appends=['restaurant_logo_url','app_logo_url','customer_app_logo_url','staff_app_logo_url','system_logo_url','favicon_url','login_cover_url'];
 private function assetUrl(?string $path): ?string{return $path?asset(ltrim($path,'/')):null;}
 public function getRestaurantLogoUrlAttribute(): ?string{return $this->assetUrl($this->restaurant_logo_path);}
 public function getAppLogoUrlAttribute(): ?string{return $this->assetUrl($this->customer_app_logo_path ?: $this->system_logo_path ?: $this->staff_app_logo_path);}
 public function getCustomerAppLogoUrlAttribute(): ?string{return $this->app_logo_url;}
 public function getStaffAppLogoUrlAttribute(): ?string{return $this->app_logo_url;}
 public function getSystemLogoUrlAttribute(): ?string{return $this->app_logo_url;}
 public function getFaviconUrlAttribute(): ?string{return $this->app_logo_url ?: $this->assetUrl($this->favicon_path);}
 public function getLoginCoverUrlAttribute(): ?string{return $this->assetUrl($this->login_cover_path);}
 protected function casts(): array{return [
  'tax_rate'=>'decimal:2','game_duration_minutes'=>'integer','kitchen_refresh_seconds'=>'integer','device_offline_minutes'=>'integer',
  'automation_enabled'=>'boolean','auto_backup_enabled'=>'boolean','backup_retention_days'=>'integer',
  'pending_order_alert_minutes'=>'integer','service_request_alert_minutes'=>'integer',
  'last_automation_at'=>'datetime','last_backup_at'=>'datetime',
  'setup_started_at'=>'datetime','setup_completed_at'=>'datetime',
 ];}
}
