<?php
session_start();
$loggedIn = isset($_SESSION['auth']) && $_SESSION['auth'] === true;
$dbConnected = isset($_SESSION['db']);
$logDbConnected = isset($_SESSION['logdb']);
$permissions = isset($_SESSION['permissions']) ? explode(',', $_SESSION['permissions']) : [];
$canViewSettings = in_array('view_settings',$permissions);
$canEditSlug = in_array('edit_slug',$permissions);
$canViewLogs = in_array('view_logs',$permissions);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>داشبورد مدیریت ووکامرس</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4@5/bootstrap-4.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-grid.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ag-grid-community/styles/ag-theme-alpine.css">
<link rel="stylesheet" href="https://unpkg.com/gridjs/dist/theme/mermaid.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4/animate.min.css"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community/dist/ag-grid-community.min.js"></script>
<script src="https://unpkg.com/gridjs/dist/gridjs.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/nprogress@0.2.0/nprogress.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/nprogress@0.2.0/nprogress.css">
<script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@39.0.1/build/ckeditor.js"></script>
<style>
body {font-family:'Vazirmatn', sans-serif; background-color:#f7f7f7;}
#login-box, #db-box {max-width:400px; margin-top:100px;}
.navbar-brand{padding:0 .75rem;margin-right:1rem;}
#profileMenu{margin-left:1rem;}
#pageTimer{margin-right:1rem; font-size:.85rem;}
footer{font-size:.9rem;}
#logPanel{max-height:200px; overflow-y:auto;}
.section-card{cursor:pointer;}
</style>
</head>
<body>

<?php if(!$loggedIn): ?>
<div class="container">
<div id="login-box" class="mx-auto animate__animated animate__fadeInDown">
  <div class="card shadow-sm">
    <div class="card-body text-center">
      <i class="fa-brands fa-apple fa-3x mb-3"></i>
      <div class="mb-3">
        <input type="text" id="username" class="form-control text-center" placeholder="Apple ID">
      </div>
      <div class="mb-3">
        <input type="password" id="password" class="form-control text-center" placeholder="Password">
      </div>
      <button id="login-btn" class="btn btn-dark w-100">ورود</button>
    </div>
  </div>
</div>
</div>
<script>
$('#login-btn').click(function(){
   $.post('ajax.php',{action:'login',username:$('#username').val(),password:$('#password').val()},function(res){
     if(res.success){
       location.reload();
     }else{
       Swal.fire('خطا',res.message,'error');
     }
   },'json');
});
</script>
<?php elseif(!$dbConnected): ?>
<div class="container">
<div id="db-box" class="mx-auto">
  <div class="card">
    <div class="card-header text-center">اتصال به پایگاه داده</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">نام میزبان</label>
        <input type="text" id="db_host" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">نام پایگاه</label>
        <input type="text" id="db_name" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" id="db_user" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">رمز عبور</label>
        <input type="password" id="db_pass" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">پیشوند جداول</label>
        <input type="text" id="db_prefix" class="form-control" value="wp_">
      </div>
      <div class="d-flex justify-content-end">
        <button id="connect-btn" class="btn btn-primary">اتصال</button>
      </div>
    </div>
  </div>
</div>
</div>
<script>
$(function(){
  $.post('ajax.php',{action:'load_saved_config'},function(res){
    if(res.success){
      $('#db_host').val(res.host);
      $('#db_name').val(res.name);
      $('#db_user').val(res.user);
      $('#db_pass').val(res.pass);
      if(res.prefix){ $('#db_prefix').val(res.prefix); }
      $.post('ajax.php',{
        action:'db_connect',
        host:res.host,
        name:res.name,
        user:res.user,
        pass:res.pass,
        prefix:res.prefix
      },function(r){ if(r.success){ location.reload(); } },'json');
    }
  },'json');
});
$('#connect-btn').click(function(){
   $.post('ajax.php',{
      action:'db_connect',
      host:$('#db_host').val(),
      name:$('#db_name').val(),
      user:$('#db_user').val(),
      pass:$('#db_pass').val(),
      prefix:$('#db_prefix').val()
   },function(res){
      if(res.success){
        location.reload();
      }else{
        Swal.fire('خطا',res.message,'error');
      }
   },'json');
});
</script>

<?php elseif(!$logDbConnected): ?>
<div class="container">
<div id="localdb-box" class="mx-auto">
  <div class="card">
    <div class="card-header text-center">اتصال پایگاه داده سامانه</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">نام میزبان</label>
        <input type="text" id="local_host" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">نام پایگاه</label>
        <input type="text" id="local_name" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" id="local_user" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">رمز عبور</label>
        <input type="password" id="local_pass" class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">پیشوند جداول</label>
        <input type="text" id="local_prefix" class="form-control" value="msw_">
      </div>
      <button id="local-connect-btn" class="btn btn-primary w-100">اتصال</button>
    </div>
  </div>
</div>
</div>
<script>
$(function(){
  $.post('ajax.php',{action:'local_load_config'},function(res){
    if(res.success){
      $('#local_host').val(res.host);
      $('#local_name').val(res.name);
      $('#local_user').val(res.user);
      $('#local_pass').val(res.pass);
      $('#local_prefix').val(res.prefix);
      $.post('ajax.php',{action:'local_db_connect',host:res.host,name:res.name,user:res.user,pass:res.pass,prefix:res.prefix},function(r){if(r.success){location.reload();}},'json');
    }
  },'json');
});
$('#local-connect-btn').click(function(){
  $.post('ajax.php',{
    action:'local_db_connect',
    host:$('#local_host').val(),
    name:$('#local_name').val(),
    user:$('#local_user').val(),
    pass:$('#local_pass').val(),
    prefix:$('#local_prefix').val()
  },function(res){
    if(res.success){ location.reload(); }else{ Swal.fire('خطا',res.message,'error'); }
  },'json');
});
</script>
<?php else: ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark w-100">
  <a class="navbar-brand ms-3" href="#"><i class="fa-solid fa-screwdriver-wrench me-2"></i>بخش مدیریت</a>
  <span id="pageTimer" class="text-light"></span>
  <div class="dropdown ms-auto me-3 d-flex align-items-center">
    <span class="text-light me-2"><?=$_SESSION['username']?></span>
    <a class="nav-link dropdown-toggle text-light" href="#" id="profileMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa-solid fa-user"></i></a>
    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileMenu">
      <li><a class="dropdown-item" href="#" id="myAccount">مدیریت حساب</a></li>
      <li><a class="dropdown-item" href="#" id="myLogs">لاگ من</a></li>
      <?php if($canViewSettings): ?><li><a class="dropdown-item" href="#" id="goSettings">تنظیمات</a></li><?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="#" id="logoutLink">خروج</a></li>
    </ul>
  </div>
</nav>
<div class="container-fluid mt-4">
<ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#products" type="button">محصولات</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#analytics" type="button">گزارش‌ها</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#bulk" type="button">اقدامات دست‌جمعی</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#users" type="button">کاربران</button>
  </li>
  <?php if($canViewLogs): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#logs" type="button">لاگ ورود و خروج</button>
  </li>
  <?php endif; ?>
  <?php if($canViewSettings): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#settings" type="button">تنظیمات</button>
  </li>
  <?php endif; ?>
</ul>
<div class="tab-content mt-4">
<div class="tab-pane fade show active p-3" id="products">
<div id="productsGrid" class="ag-theme-alpine" style="height:400px;"></div>
</div>
<div class="tab-pane fade p-3" id="analytics">
  <section class="mb-5">
   <div class="card shadow-sm">
    <div class="card-header">توزیع محصولات بر اساس دسته‌بندی</div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-lg-6 text-center">
          <canvas id="catChart" class="mx-auto" style="max-height:400px"></canvas>
        </div>
        <div class="col-lg-6">
          <table class="table table-sm table-striped" id="catTable">
            <thead><tr><th>دسته</th><th>تعداد</th><th>درصد</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
   </div>
  </section>
  <section class="mb-5">
   <div class="card shadow-sm">
    <div class="card-header">وضعیت سئو محصولات</div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-lg-6 text-center">
          <canvas id="seoChart" class="mx-auto" style="max-height:300px"></canvas>
        </div>
        <div class="col-lg-6">
          <table class="table table-sm table-striped" id="seoTable">
            <thead><tr><th>وضعیت</th><th>تعداد</th><th>درصد</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
   </div>
  </section>
  <section class="mb-5">
   <div class="card shadow-sm">
    <div class="card-header">وضعیت موجودی</div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-lg-6 text-center">
          <canvas id="stockChart" class="mx-auto" style="max-height:300px"></canvas>
        </div>
        <div class="col-lg-6">
          <table class="table table-sm table-striped" id="stockTable">
            <thead><tr><th>وضعیت</th><th>تعداد</th><th>درصد</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
   </div>
  </section>
  <section class="mb-5">
   <div class="card shadow-sm">
    <div class="card-header">محصولات بدون قیمت</div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-lg-6 text-center">
          <canvas id="priceChart" class="mx-auto" style="max-height:300px"></canvas>
        </div>
        <div class="col-lg-6">
          <table class="table table-sm table-striped" id="priceTable">
            <thead><tr><th>نوع</th><th>تعداد</th><th>درصد</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
   </div>
  </section>
</div>
  <div class="tab-pane fade p-3" id="bulk">
  <div class="card mb-3">
    <div class="card-header">مدیریت موجودی</div>
    <div class="card-body">
      <p class="text-muted small">موجود یا ناموجود کردن همه محصولات. این کار باعث جلوگیری از خرید در زمان تغییر قیمت می‌شود.</p>
      <button class="btn btn-success me-2" id="bulkStockIn">موجود کردن همه محصولات</button>
      <button class="btn btn-danger" id="bulkStockOut">ناموجود کردن همه محصولات</button>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-header">تغییر قیمت دسته‌جمعی</div>
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">مقدار</label>
          <input type="number" id="bulkPriceVal" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">نوع</label>
          <select id="bulkPriceType" class="form-select">
            <option value="percent">درصد</option>
            <option value="fixed">عدد ثابت</option>
          </select>
        </div>
        <div class="col-md-6">
          <button class="btn btn-success me-2" id="bulkPriceInc">افزایش قیمت</button>
          <button class="btn btn-danger" id="bulkPriceDec">کاهش قیمت</button>
        </div>
      </div>
    </div>
  </div>
    <div class="card mb-3">
      <div class="card-header">اقدامات دسته‌جمعی سئو</div>
      <div class="card-body">
        <div class="mb-3">
          <button class="btn btn-primary" id="bulkSeoKeywords">کپی نام محصول در Meta Keywords</button>
    </div>
    <div class="mb-3">
      <button class="btn btn-secondary" id="bulkSeoDesc">تولید توضیحات متا</button>
    </div>
  </div>
</div>
  </div>
  <div class="tab-pane fade p-3" id="users">
    <div class="d-flex justify-content-end mb-3">
      <button class="btn btn-success" id="addUserBtn">کاربر جدید</button>
    </div>
    <div id="usersTable"></div>
  </div>
  <?php if($canViewLogs): ?>
  <div class="tab-pane fade p-3" id="logs">
    <div id="logsTable"></div>
  </div>
  <?php endif; ?>
  <?php if($canViewSettings): ?>
  <div class="tab-pane fade p-3" id="settings">
    <div class="row g-3">
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#configModal">
          <div class="card-body">
            <i class="fa fa-database fa-2x mb-2"></i>
            <div>تنظیمات پایگاه داده</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#localConfigModal">
          <div class="card-body">
            <i class="fa fa-database fa-2x mb-2"></i>
            <div>پایگاه داده سامانه</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#promptModal">
          <div class="card-body">
            <i class="fa fa-robot fa-2x mb-2"></i>
            <div>پرامپت هوش مصنوعی</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#licenseModal">
          <div class="card-body">
            <i class="fa fa-key fa-2x mb-2"></i>
            <div>لایسنس‌ها</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#apiModal">
          <div class="card-body">
            <i class="fa fa-globe fa-2x mb-2"></i>
            <div>API ها</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>

 <footer class="bg-dark text-light mt-5">
  <div class="container py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
      <div>
        <span id="footerSite"><?=$_SERVER['HTTP_HOST']?></span>
        <span id="ipInfo" class="ms-2"></span>
      </div>
      <div class="mb-2 mb-md-0">
        <button id="toggleLog" class="btn btn-outline-light btn-sm me-2"><i class="fa fa-bug"></i></button>
        <button id="copyLog" class="btn btn-outline-light btn-sm"><i class="fa fa-copy"></i></button>
      </div>
    </div>
    <pre id="logPanel" class="bg-secondary text-light mt-3 p-2 d-none small"></pre>
    <div class="text-center mt-3">
      <small>© 2024 کلیه حقوق محفوظ است - این سامانه توسط پدرام نخستین طراحی و توسعه داده شده است.</small>
    </div>
 </div>
</footer>
 
<div class="modal fade" id="configModal" tabindex="-1">
  <div class="modal-dialog">
   <div class="modal-content">
    <div class="modal-header">
     <h5 class="modal-title">تنظیمات پایگاه داده</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
     <div id="cfgStatus" class="mb-3 small"></div>
     <div class="mb-3">
      <label class="form-label">نام میزبان</label>
      <input type="text" id="cfg_host" class="form-control">
     </div>
     <div class="mb-3">
      <label class="form-label">نام پایگاه</label>
      <input type="text" id="cfg_name" class="form-control">
     </div>
     <div class="mb-3">
      <label class="form-label">نام کاربری</label>
      <input type="text" id="cfg_user" class="form-control">
     </div>
     <div class="mb-3">
      <label class="form-label">رمز عبور</label>
      <input type="password" id="cfg_pass" class="form-control">
     </div>
     <div class="mb-3">
      <label class="form-label">پیشوند جداول</label>
      <input type="text" id="cfg_prefix" class="form-control">
     </div>
    </div>
    <div class="modal-footer">
     <button id="cfgSave" class="btn btn-primary" type="button">ذخیره</button>
    </div>
   </div>
  </div>
</div>

<div class="modal fade" id="localConfigModal" tabindex="-1">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">تنظیمات پایگاه داده سامانه</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div id="localCfgStatus" class="mb-3 small"></div>
    <div class="mb-3">
     <label class="form-label">نام میزبان</label>
     <input type="text" id="localCfg_host" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">نام پایگاه</label>
     <input type="text" id="localCfg_name" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">نام کاربری</label>
     <input type="text" id="localCfg_user" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">رمز عبور</label>
     <input type="password" id="localCfg_pass" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">پیشوند جداول</label>
     <input type="text" id="localCfg_prefix" class="form-control">
    </div>
   </div>
   <div class="modal-footer">
    <button id="localCfgSave" class="btn btn-primary" type="button">ذخیره</button>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="promptModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
   <div class="modal-content">
    <div class="modal-header">
     <h5 class="modal-title">پرامپت هوش مصنوعی</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
     <textarea id="promptTemplate"></textarea>
    </div>
    <div class="modal-footer">
     <button id="savePrompt" class="btn btn-success" type="button">ذخیره</button>
    </div>
   </div>
  </div>
</div>

<div class="modal fade" id="licenseModal" tabindex="-1">
  <div class="modal-dialog">
   <div class="modal-content">
    <div class="modal-header">
     <h5 class="modal-title">مدیریت لایسنس‌ها</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
     <div id="licenseList"></div>
     <button id="addLicense" class="btn btn-sm btn-secondary mt-2" type="button">افزودن لایسنس</button>
    </div>
    <div class="modal-footer">
     <button id="saveLicenses" class="btn btn-primary" type="button">ذخیره</button>
    </div>
   </div>
</div>
</div>

<div class="modal fade" id="apiModal" tabindex="-1">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">تنظیمات API</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="mb-3">
     <label class="form-label">کلید Geo.IPify</label>
     <input type="text" id="ipifyKey" class="form-control">
    </div>
   </div>
   <div class="modal-footer">
    <button id="saveApiSettings" class="btn btn-primary" type="button">ذخیره</button>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
 <div class="modal-dialog">
  <form id="userForm" class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">کاربر</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <input type="hidden" id="user_id">
    <div class="mb-3">
     <label class="form-label">نام کاربری/ایمیل</label>
     <input type="text" id="user_username" class="form-control" required>
    </div>
    <div class="mb-3">
     <label class="form-label">رمز عبور</label>
     <input type="password" id="user_password" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">نام کامل</label>
     <input type="text" id="user_fullname" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">شماره تلفن</label>
     <input type="text" id="user_phone" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">نقش</label>
     <select id="user_role" class="form-select">
      <option value="admin">مدیر</option>
      <option value="client">مشتری</option>
      <option value="user" selected>کاربر</option>
     </select>
    </div>
    <div class="mb-3">
     <label class="form-label">وضعیت</label>
     <select id="user_status" class="form-select">
      <option value="active" selected>فعال</option>
      <option value="inactive">غیرفعال</option>
      <option value="banned">مسدود</option>
     </select>
    </div>
    <div class="mb-3">
     <label class="form-label">دسترسی‌ها</label>
     <div class="form-check">
      <input class="form-check-input perm" type="checkbox" value="manage_products" id="perm_products">
      <label class="form-check-label" for="perm_products">مدیریت محصولات</label>
     </div>
     <div class="form-check">
     <input class="form-check-input perm" type="checkbox" value="view_logs" id="perm_logs">
     <label class="form-check-label" for="perm_logs">مشاهده لاگ‌ها</label>
    </div>
    <div class="form-check">
      <input class="form-check-input perm" type="checkbox" value="manage_users" id="perm_users">
      <label class="form-check-label" for="perm_users">مدیریت کاربران</label>
    </div>
    <div class="form-check">
      <input class="form-check-input perm" type="checkbox" value="view_settings" id="perm_settings">
      <label class="form-check-label" for="perm_settings">مشاهده تنظیمات</label>
    </div>
    <div class="form-check">
      <input class="form-check-input perm" type="checkbox" value="edit_slug" id="perm_slug">
      <label class="form-check-label" for="perm_slug">ویرایش نامک</label>
    </div>
    </div>
   </div>
   <div class="modal-footer">
    <button type="submit" class="btn btn-primary">ذخیره</button>
   </div>
  </form>
</div>
</div>

<div class="modal fade" id="logModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">لاگ کاربر: <span id="logUser"></span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <canvas id="logChart" class="mb-3" style="max-height:300px"></canvas>
    <div id="userLogTable" class="text-end" style="direction:ltr;"></div>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
 <div class="modal-dialog modal-xl modal-dialog-scrollable">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">ویرایش محصول: <span id="modalProdName"></span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
     <form id="editForm">
       <input type="hidden" name="id" id="prod_id">
       <div class="mb-3">
         <label class="form-label">نام محصول</label>
         <input type="text" id="prod_name" class="form-control">
       </div>
       <div class="mb-3">
       <label class="form-label">نامک محصول</label>
        <div class="input-group">
           <input type="text" id="prod_slug" class="form-control" <?php if(!$canEditSlug) echo 'disabled';?>>
           <?php if($canEditSlug): ?>
           <button class="btn btn-outline-secondary" type="button" id="editSlug">ویرایش</button>
           <button class="btn btn-outline-secondary" type="button" id="genSlug">ایجاد نامک انگلیسی</button>
           <?php endif; ?>
         </div>
       </div>
       <div class="mb-3">
         <label class="form-label">توضیحات</label>
         <ul class="nav nav-tabs" id="descTabs">
           <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#desc-editor" type="button">متن</button></li>
           <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#desc-html" type="button">HTML</button></li>
         </ul>
         <div class="tab-content border border-top-0 p-2">
           <div class="tab-pane fade show active" id="desc-editor">
             <textarea id="prod_desc" class="form-control"></textarea>
           </div>
           <div class="tab-pane fade" id="desc-html">
             <textarea id="prod_desc_html" class="form-control" style="min-height:300px;direction:ltr;text-align:left;"></textarea>
           </div>
         </div>
       </div>
       <div class="mb-3">
       <label class="form-label">قیمت (تومان)</label>
       <input type="text" id="prod_price" class="form-control">
       <div class="form-text">مبلغ را به تومان وارد کنید.</div>
      </div>
       <div class="mb-3">
         <label class="form-label">انبارداری</label>
         <select id="stock_status" class="form-select">
           <option value="instock">موجود</option>
           <option value="outofstock">ناموجود</option>
         </select>
       </div>
       <div class="mb-3">
         <label class="form-label">دسته‌ها</label>
         <div id="cat_list" class="d-flex flex-wrap"></div>
       </div>
       <div class="mb-3">
         <label class="form-label">عنوان سئو</label>
         <input type="text" id="seo_title" class="form-control">
       </div>
       <div class="mb-3">
       <label class="form-label">توضیحات متا</label>
       <textarea id="seo_desc" class="form-control"></textarea>
      </div>
       <div class="mb-3">
         <label class="form-label">عبارت کلیدی متا</label>
         <input type="text" id="seo_focus" class="form-control">
       </div>
       <div class="mb-3">
         <label class="form-label">نمره سئو: <span id="seo_score" class="badge bg-secondary">0</span></label>
         <ul id="seo_feedback" class="small mt-2 mb-0"></ul>
       </div>
       <div class="mb-3">
         <label class="form-label">پرامپت سئو (ChatGPT)</label>
         <div class="input-group">
          <textarea id="seo_prompt" class="form-control" rows="5" readonly></textarea>
          <button class="btn btn-outline-secondary" type="button" id="copyPrompt">کپی</button>
         </div>
       </div>
     </form>
   </div>
 <div class="modal-footer">
    <a href="#" class="btn btn-secondary" target="_blank" id="viewProduct">نمایش محصول</a>
    <button type="button" class="btn btn-success" id="saveBtn">ذخیره</button>
  </div>
 </div>
</div>
</div>

<div class="modal fade" id="adminSetupModal" tabindex="-1">
 <div class="modal-dialog">
  <form id="adminSetupForm" class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">ایجاد مدیر سامانه</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="mb-3">
     <label class="form-label">نام کاربری</label>
     <input type="text" id="admin_username" class="form-control" required>
    </div>
    <div class="mb-3">
     <label class="form-label">رمز عبور</label>
     <input type="password" id="admin_password" class="form-control" required>
    </div>
   </div>
   <div class="modal-footer">
    <button type="submit" class="btn btn-primary">ثبت</button>
   </div>
  </form>
 </div>
</div>

<script>
const currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;
const myUsername = <?= json_encode($_SESSION['username'] ?? '') ?>;
let licenses={};
let descEditor, promptEditor;
$.post('ajax.php',{action:'load_licenses'},function(res){
 if(res.success) licenses=res.data;
 ClassicEditor.create(document.querySelector('#prod_desc'),{
   language:'fa',
   licenseKey: licenses.ckeditor || ''
 }).then(ed=>{ descEditor=ed; ed.model.document.on('change:data',updateSeoScore); });
},'json');

$('#descTabs button[data-bs-target="#desc-html"]').on('shown.bs.tab',function(){
  $('#prod_desc_html').val(descEditor.getData());
  updateSeoScore();
});
$('#descTabs button[data-bs-target="#desc-editor"]').on('shown.bs.tab',function(){
  descEditor.setData($('#prod_desc_html').val());
  updateSeoScore();
});
$('#prod_price').on('input',function(){
  let v=$(this).val().replace(/[^0-9]/g,'');
  if(v) $(this).val(v.replace(/\B(?=(\d{3})+(?!\d))/g,','));
});
$('#copyPrompt').click(function(){
  navigator.clipboard.writeText($('#seo_prompt').val());
  toastr.info('کپی شد');
});
$('#editSlug').click(function(){ $('#prod_slug').prop('disabled',false).focus(); });
$('#genSlug').click(function(){
  const name = $('#prod_name').val();
  if(!name){ toastr.error('نام محصول را وارد کنید'); return; }
  NProgress.start();
  $.ajax({
    url:'https://api.mymemory.translated.net/get',
    method:'GET',
    data:{q:name, langpair:'fa|en'},
    success:function(res){
      NProgress.done();
      if(res && res.responseData && res.responseData.translatedText){
        let slug=res.responseData.translatedText.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
        $('#prod_slug').val(slug);
      }else{
        toastr.error('ترجمه ناموفق بود');
      }
    },
    error:function(){ NProgress.done(); toastr.error('ترجمه ناموفق بود'); }
  });
});

$('#configModal').on('shown.bs.modal',function(){
  $('#cfgStatus').text('');
  $.post('ajax.php',{action:'load_saved_config'},function(res){
    if(res.success){
      $('#cfg_host').val(res.host);
      $('#cfg_name').val(res.name);
      $('#cfg_user').val(res.user);
      $('#cfg_pass').val(res.pass);
      $('#cfg_prefix').val(res.prefix);
    }
  },'json');
  $.post('ajax.php',{action:'check_config'},function(res){
    const el=$('#cfgStatus');
    if(res.success){ el.text('اتصال برقرار است').removeClass('text-danger').addClass('text-success'); }
    else { el.text(res.message).removeClass('text-success').addClass('text-danger'); }
  },'json');
});

$('#cfgSave').click(function(){
  $.post('ajax.php',{
    action:'db_connect',
    host:$('#cfg_host').val(),
    name:$('#cfg_name').val(),
    user:$('#cfg_user').val(),
    pass:$('#cfg_pass').val(),
    prefix:$('#cfg_prefix').val()
  },function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#configModal').modal('hide'); location.reload(); }
    else{ Swal.fire('خطا',res.message,'error'); }
  },'json');
});

$('#localConfigModal').on('shown.bs.modal',function(){
  $('#localCfgStatus').text('');
  $.post('ajax.php',{action:'local_load_config'},function(res){
    if(res.success){
      $('#localCfg_host').val(res.host);
      $('#localCfg_name').val(res.name);
      $('#localCfg_user').val(res.user);
      $('#localCfg_pass').val(res.pass);
      $('#localCfg_prefix').val(res.prefix);
    }
  },'json');
  $.post('ajax.php',{action:'local_check_config'},function(res){
    const el=$('#localCfgStatus');
    if(res.success){ el.text('اتصال برقرار است').removeClass('text-danger').addClass('text-success'); }
    else { el.text(res.message).removeClass('text-success').addClass('text-danger'); }
  },'json');
});

$('#localCfgSave').click(function(){
  $.post('ajax.php',{
    action:'local_db_connect',
    host:$('#localCfg_host').val(),
    name:$('#localCfg_name').val(),
    user:$('#localCfg_user').val(),
    pass:$('#localCfg_pass').val(),
    prefix:$('#localCfg_prefix').val()
  },function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#localConfigModal').modal('hide'); location.reload(); }
    else{ Swal.fire('خطا',res.message,'error'); }
  },'json');
});

$('#promptModal').on('shown.bs.modal',function(){
  const init = ()=>{
    $.post('ajax.php',{action:'load_prompt_template'},function(res){ if(res.success){ promptEditor.setData(res.template); } },'json');
  };
  if(!promptEditor){
    ClassicEditor.create(document.querySelector('#promptTemplate'),{language:'fa',licenseKey: licenses.ckeditor || ''}).then(ed=>{promptEditor=ed; init();});
  }else{ init(); }
});

$('#savePrompt').click(function(){
  if(promptEditor){
    $.post('ajax.php',{action:'save_prompt_template',template:promptEditor.getData()},function(res){
      if(res.success) toastr.success('ذخیره شد'); else toastr.error(res.message);
    },'json');
  }
});

$('#licenseModal').on('shown.bs.modal',function(){
  $.post('ajax.php',{action:'load_licenses'},function(res){ renderLicenses(res.success?res.data:{}); },'json');
});

$('#apiModal').on('shown.bs.modal',function(){
  $.post('ajax.php',{action:'load_api_settings'},function(res){ if(res.success){ $('#ipifyKey').val(res.ipify); } },'json');
});
$('#saveApiSettings').click(function(){
  $.post('ajax.php',{action:'save_api_settings',ipify:$('#ipifyKey').val()},function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#apiModal').modal('hide'); }
    else{ toastr.error('خطا'); }
  },'json');
});

function renderLicenses(data){
  $('#licenseList').empty();
  const keys=Object.keys(data);
  if(keys.length===0) addLicenseRow('','');
  keys.forEach(k=>addLicenseRow(k,data[k]));
}
function addLicenseRow(name,key){
  const row=$(`<div class="input-group mb-2 license-item"><input type="text" class="form-control license-name" placeholder="نام فریمورک" value="${name}"><input type="text" class="form-control license-key" placeholder="کلید لایسنس" value="${key}"><button class="btn btn-outline-danger remove-license" type="button"><i class="fa fa-times"></i></button></div>`);
  row.find('.remove-license').click(()=>row.remove());
  $('#licenseList').append(row);
}
$('#addLicense').click(()=>addLicenseRow('',''));
$('#saveLicenses').click(function(){
  const data={};
  $('#licenseList .license-item').each(function(){
    const n=$(this).find('.license-name').val().trim();
    const k=$(this).find('.license-key').val().trim();
    if(n) data[n]=k;
  });
  $.post('ajax.php',{action:'save_licenses',licenses:JSON.stringify(data)},function(res){
    if(res.success){ toastr.success('ذخیره شد'); licenses=data; }
    else{ toastr.error(res.message); }
  },'json');
});

function updateSeoScore(){
  let score=0, pos=[], neg=[];
  const keyword=$('#seo_focus').val().trim() || $('#prod_name').val().trim();
  const title=$('#seo_title').val();
  if(title.length>=50 && title.length<=65){ score+=10; pos.push('طول عنوان مناسب است'); } else neg.push('طول عنوان باید بین ۵۰ تا ۶۵ کاراکتر باشد');
  if(keyword && title.includes(keyword)){ score+=10; pos.push('کلمه کلیدی در عنوان وجود دارد'); } else if(keyword) neg.push('کلمه کلیدی در عنوان نیست');
  const meta=$('#seo_desc').val();
  if(meta.length>=120 && meta.length<=155){ score+=10; pos.push('طول توضیحات متا مناسب است'); } else neg.push('توضیحات متا باید بین ۱۲۰ تا ۱۵۵ کاراکتر باشد');
  if(keyword && meta.includes(keyword)){ score+=10; pos.push('کلمه کلیدی در توضیحات متا وجود دارد'); } else if(keyword) neg.push('کلمه کلیدی در توضیحات متا نیست');
  let contentHtml='';
  if($('#descTabs .nav-link.active').attr('data-bs-target') === '#desc-html'){
    contentHtml=$('#prod_desc_html').val();
  }else{
    contentHtml=descEditor.getData();
  }
  let contentText=contentHtml.replace(/<[^>]*>/g,' ').trim();
  const words=contentText.split(/\s+/).filter(w=>w);
  const wordCount=words.length;
  if(wordCount>=300){ score+=10; pos.push('متن توضیحات کافی است'); } else neg.push('حداقل ۳۰۰ کلمه در توضیحات بنویسید');
  if(keyword){
    const regex=new RegExp(keyword,'gi');
    const matches=contentText.match(regex)||[];
    const density=wordCount ? (matches.length/wordCount)*100 : 0;
    if(density>=0.5 && density<=3){ score+=10; pos.push('چگالی کلمه کلیدی مناسب است'); } else neg.push('چگالی کلمه کلیدی خارج از محدوده ۰.۵٪ تا ۳٪ است');
    const firstPara=contentText.split(/\n+/)[0]||'';
    if(firstPara.toLowerCase().includes(keyword.toLowerCase())){ score+=10; pos.push('کلمه کلیدی در پاراگراف اول دیده می‌شود'); } else neg.push('کلمه کلیدی در پاراگراف اول نیست');
  }
  const links = contentHtml.match(/<a\s+[^>]*href=['"]([^'"]+)['"]/gi) || [];
  let internal=0, external=0;
  links.forEach(l=>{
    const m=l.match(/href=['"]([^'"]+)['"]/i);
    if(m){ const url=m[1]; if(url.startsWith('http')) external++; else internal++; }
  });
  if(internal>0){ score+=10; pos.push('حداقل یک لینک داخلی وجود دارد'); } else neg.push('هیچ لینک داخلی یافت نشد');
  if(external>0){ score+=10; pos.push('حداقل یک لینک خارجی وجود دارد'); } else neg.push('هیچ لینک خارجی یافت نشد');
  const sentences=contentText.split(/[.!؟\?]/).filter(s=>s.trim().length>0);
  const avg=sentences.length ? wordCount/sentences.length : wordCount;
  if(avg<=20){ score+=10; pos.push('میانگین طول جمله مناسب است'); } else neg.push('میانگین طول جمله بیش از ۲۰ کلمه است');
  let badge='bg-danger';
  if(score>=70) badge='bg-success'; else if(score>=40) badge='bg-warning';
  $('#seo_score').removeClass().addClass('badge '+badge).text(score);
  let html='';
  pos.forEach(p=>html+=`<li class="text-success">${p}</li>`);
  neg.forEach(n=>html+=`<li class="text-danger">${n}</li>`);
  $('#seo_feedback').html(html);
}
$('#seo_title,#seo_desc,#prod_name,#seo_focus').on('input',updateSeoScore);
$('#prod_desc_html').on('input',updateSeoScore);

function log(msg){
 const ts=new Date().toISOString();
 const line=`[${ts}] ${msg}`;
 $('#logPanel').text($('#logPanel').text()+line+"\n");
 $('#logPanel').scrollTop($('#logPanel')[0].scrollHeight);
 console.log(line);
}
$('#toggleLog').click(()=>$('#logPanel').toggleClass('d-none'));
$('#copyLog').click(()=>{ navigator.clipboard.writeText($('#logPanel').text()); toastr.info('کپی شد'); });
$(document).ajaxStart(()=>NProgress.start());
$(document).ajaxStop(()=>NProgress.done());

const columnDefs=[
 {headerName:'تصویر',field:'image',width:80,cellRenderer:params=>`<img src="${params.value}" width="50" height="50">`},
 {headerName:'نام',field:'name',flex:1},
 {headerName:'قیمت',field:'price',width:120},
 {headerName:'انبارداری',field:'stock',width:120},
 {headerName:'سئو',field:'seo',width:90},
 {headerName:'ویرایش',field:'id',width:90,cellRenderer:params=>`<button class="btn btn-sm btn-primary edit" data-id="${params.value}">ویرایش</button>`},
 {headerName:'نمایش',field:'link',width:90,cellRenderer:params=>`<a class="btn btn-sm btn-outline-secondary" target="_blank" href="${params.value}">نمایش</a>`}
];
const gridOptions={columnDefs:columnDefs,rowData:[],defaultColDef:{resizable:true}};
const gridDiv=document.querySelector('#productsGrid');
new agGrid.Grid(gridDiv,gridOptions);
function loadProducts(){
 fetch('ajax.php',{method:'POST',headers:{"Content-Type":"application/x-www-form-urlencoded"},body:'action=list_products'})
 .then(r=>r.json()).then(r=>{ if(r.success){ gridOptions.api.setRowData(r.data); }});
}
loadProducts();

$(document).on('click','.edit',function(){
 var id=$(this).data('id');
 $.post('ajax.php',{action:'get_product',id:id},function(res){
  if(res.success){
    $('#prod_id').val(res.product.id);
    $('#modalProdName').text(res.product.name);
    $('#prod_name').val(res.product.name);
    $('#prod_slug').val(res.product.slug).data('old',res.product.slug).prop('disabled',true);
    descEditor.setData(res.product.description);
    $('#prod_desc_html').val(res.product.description);
    $('#prod_price').val(res.product.price ? res.product.price.replace(/\B(?=(\d{3})+(?!\d))/g,',') : '');
    $('#stock_status').val(res.stock_status);
    $('#cat_list').html(res.categories_html);
    $('#seo_title').val(res.seo_title);
    $('#seo_desc').val(res.seo_desc);
    $('#seo_focus').val(res.focus_keyword);
    updateSeoScore();
    $('#seo_prompt').val(res.seo_prompt);
    $('#viewProduct').attr('href', res.product_url);
    var modal=new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
  }else{
    Swal.fire('خطا',res.message,'error');
   }
 },'json');
});

$('#saveBtn').click(function(){
  Swal.fire({
    title:'ذخیره تغییرات؟',
    icon:'question',
    showCancelButton:true,
    confirmButtonText:'بله',
    cancelButtonText:'خیر'
  }).then((result)=>{
    if(result.isConfirmed){
      NProgress.start();
      if($('#descTabs .nav-link.active').attr('data-bs-target') === '#desc-html'){
        descEditor.setData($('#prod_desc_html').val());
      }
      $.post('ajax.php',{
        action:'save_product',
        id:$('#prod_id').val(),
        name:$('#prod_name').val(),
        slug:$('#prod_slug').val(),
        old_slug:$('#prod_slug').data('old'),
        description:descEditor.getData(),
        price:$('#prod_price').val().replace(/,/g,''),
        stock_status:$('#stock_status').val(),
        categories:$('#editForm input[name="cats[]"]:checked').map(function(){return this.value;}).get(),
        seo_title:$('#seo_title').val(),
        seo_desc:$('#seo_desc').val(),
        focus_kw:$('#seo_focus').val()
      },function(res){
        NProgress.done();
        if(res.success){
          toastr.success('با موفقیت ذخیره شد');
          if($('#prod_slug').data('old')!==$('#prod_slug').val()){
            if(res.redirect){
              toastr.success('ریدایرکت 301 با موفقیت ثبت شد');
            }else{
              toastr.error('ثبت ریدایرکت با خطا مواجه شد');
            }
            $('#prod_slug').data('old',$('#prod_slug').val());
          }
          table.ajax.reload(null,false);
          bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        }else{
          toastr.error(res.message);
        }
      },'json');
    }
  });
});


const startTime = Date.now();
setInterval(()=>{
  const diff = Date.now()-startTime;
  const m = Math.floor(diff/60000);
  const s = Math.floor((diff%60000)/1000);
  $('#pageTimer').text(`مدت زمان حضور شما: ${m} دقیقه و ${s} ثانیه`);
},1000);

fetch('https://ipapi.co/json/').then(r=>r.json()).then(d=>{
  $('#ipInfo').html(`${d.ip} <img src="https://cdn.jsdelivr.net/npm/flag-icons@6.7.0/flags/4x3/${d.country_code.toLowerCase()}.svg" width="20" class="ms-1">`);
});

$('#bulkStockIn').click(function(){
  Swal.fire({title:'آیا مطمئن هستید؟',text:'این کار باعث جلوگیری از خرید در زمان تغییر قیمت می‌شود.',icon:'warning',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      NProgress.start();
      $.post('ajax.php',{action:'bulk_stock',status:'instock'},function(r){
        NProgress.done();
        if(r.success){toastr.success('به‌روزرسانی شد');}else{toastr.error(r.message);} 
      },'json');
    }
  });
});

$('#bulkStockOut').click(function(){
  Swal.fire({title:'آیا مطمئن هستید؟',text:'این کار باعث جلوگیری از خرید در زمان تغییر قیمت می‌شود.',icon:'warning',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      NProgress.start();
      $.post('ajax.php',{action:'bulk_stock',status:'outofstock'},function(r){
        NProgress.done();
        if(r.success){toastr.success('به‌روزرسانی شد');}else{toastr.error(r.message);} 
      },'json');
    }
  });
});

function bulkPrice(op){
  const val=$('#bulkPriceVal').val();
  if(!val){ toastr.error('مقدار را وارد کنید'); return; }
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      NProgress.start();
      $.post('ajax.php',{action:'bulk_price',op:op,type:$('#bulkPriceType').val(),value:val},function(r){
        NProgress.done();
        if(r.success){toastr.success('قیمت‌ها به‌روزرسانی شد');}else{toastr.error(r.message);} 
      },'json');
    }
  });
}
$('#bulkPriceInc').click(()=>bulkPrice('inc'));
$('#bulkPriceDec').click(()=>bulkPrice('dec'));

$('#bulkSeoKeywords').click(function(){
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      NProgress.start();
      $.post('ajax.php',{action:'bulk_seo_keywords'},function(r){
        NProgress.done();
        if(r.success){toastr.success('کلمات کلیدی به‌روزرسانی شد');}else{toastr.error(r.message);} 
      },'json');
    }
  });
});

$('#bulkSeoDesc').click(function(){
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      NProgress.start();
      $.post('ajax.php',{action:'bulk_seo_desc'},function(r){
        NProgress.done();
        if(r.success){toastr.success('توضیحات متا تولید شد');}else{toastr.error(r.message);}
      },'json');
    }
  });
});

function toJalali(d){
  const date=new Date(d.replace(' ','T'));
  return date.toLocaleString('fa-IR-u-ca-persian',{dateStyle:'short',timeStyle:'short'});
}
let logTable, logChart;
function showUserLogs(id, name){
  $.post('ajax.php',{action:'fetch_user_logs',id:id},function(r){
    if(r.success){
      $('#logUser').text(name);
      const rows=r.data.map(d=>[toJalali(d.ts),d.action,d.ip,d.country,d.city,d.isp]);
      if(!logTable){
        logTable=new gridjs.Grid({columns:['زمان','عملیات','آی‌پی','کشور','شهر','ISP'],data:rows}).render(document.getElementById('userLogTable'));
      }else{
        logTable.updateConfig({data:rows}).forceRender();
      }
      const labels=Object.keys(r.counts), values=Object.values(r.counts);
      if(logChart) logChart.destroy();
      logChart=new Chart(document.getElementById('logChart'),{
        type:'bar',
        data:{labels:labels,datasets:[{label:'تعداد',data:values,backgroundColor:'#0d6efd'}]},
        options:{plugins:{legend:{display:false}}}
      });
      $('#logModal').modal('show');
    }else{
      toastr.error(r.message);
    }
  },'json');
}

$('#usersTable').on('click','.user-log',function(){
  const id=$(this).data('id');
  const name=$(this).data('name');
  showUserLogs(id,name);
});

$('#myLogs').click(function(e){
  e.preventDefault();
  showUserLogs(currentUserId,myUsername);
});

$('#goSettings').click(function(e){
  e.preventDefault();
  const tabEl=document.querySelector('#dashboardTabs button[data-bs-target="#settings"]');
  if(tabEl) new bootstrap.Tab(tabEl).show();
});

$('#logoutLink').click(function(e){
  e.preventDefault();
  $.post('ajax.php',{action:'logout'},function(){location.reload();});
});

$('#myAccount').click(function(e){
  e.preventDefault();
  $.post('ajax.php',{action:'user_get',id:currentUserId},function(r){
    if(r.success){
      $('#user_id').val(r.data.id);
      $('#user_username').val(r.data.username);
      $('#user_fullname').val(r.data.full_name);
      $('#user_phone').val(r.data.phone_number);
      $('#user_role').val(r.data.role);
      $('#user_status').val(r.data.status);
      $('.perm').prop('checked',false);
      if(r.data.permissions){ r.data.permissions.split(',').forEach(p=>$('.perm[value="'+p+'"]').prop('checked',true)); }
      $('#user_password').val('');
      $('#userModal').modal('show');
    }else{ toastr.error(r.message); }
  },'json');
});

// Analytics charts with tables
function renderTable(id, labels, data){
  let total = data.reduce((a,b)=>a+Number(b),0);
  let rows='';
  labels.forEach((label,i)=>{
    let pct = total ? ((Number(data[i])/total)*100).toFixed(1) : 0;
    rows+=`<tr><td>${label}</td><td>${data[i]}</td><td>${pct}%</td></tr>`;
  });
  $('#'+id+' tbody').html(rows);
}

function loadAnalytics(){
 $.post('ajax.php',{action:'analytics'},function(res){
  if(res.success){
    new Chart(document.getElementById('catChart'),{
      type:'bar',
      data:{labels:res.cat.labels, datasets:[{label:'تعداد', data:res.cat.data, backgroundColor:'#0d6efd'}]}
    });
    renderTable('catTable',res.cat.labels,res.cat.data);

    new Chart(document.getElementById('seoChart'),{
      type:'pie',
      data:{labels:res.seo.labels, datasets:[{data:res.seo.data, backgroundColor:['#198754','#dc3545','#6c757d']}]}
    });
    renderTable('seoTable',res.seo.labels,res.seo.data);

    new Chart(document.getElementById('stockChart'),{
      type:'bar',
      data:{labels:res.stock.labels, datasets:[{data:res.stock.data, backgroundColor:['#198754','#dc3545']}]} 
    });
    renderTable('stockTable',res.stock.labels,res.stock.data);

    new Chart(document.getElementById('priceChart'),{
      type:'bar',
      data:{labels:res.price.labels, datasets:[{data:res.price.data, backgroundColor:['#dc3545','#198754']}]} 
    });
    renderTable('priceTable',res.price.labels,res.price.data);
    log('analytics loaded: '+JSON.stringify(res));
  }else{
log('analytics error: '+res.message);
  }
},'json').fail(function(xhr){ log('analytics ajax error '+xhr.status+' '+xhr.responseText); });
}
let analyticsLoaded=false;
$('button[data-bs-target="#analytics"]').on('shown.bs.tab',function(){
 if(!analyticsLoaded){ loadAnalytics(); analyticsLoaded=true; }
});

let usersGrid;
$('#dashboardTabs button[data-bs-target="#users"]').on('shown.bs.tab',function(){
 if(!usersGrid){
  usersGrid=new gridjs.Grid({
   columns:['ID','نام کاربری','نقش','وضعیت','ایجاد','اقدامات'],
   server:{
     url:'ajax.php',
     method:'POST',
     body:{action:'users_list'},
     then:data=>data.data.map(u=>[u.id,u.username,u.role,u.status,u.created_at,gridjs.html(`<button class="btn btn-sm btn-info user-log" data-id="${u.id}" data-name="${u.username}">لاگ</button> <button class="btn btn-sm btn-primary edit-user" data-id="${u.id}">ویرایش</button> <button class="btn btn-sm btn-danger delete-user" data-id="${u.id}">حذف</button>`)] )
   },
   pagination:{limit:10}
  }).render(document.getElementById('usersTable'));
 } else { usersGrid.updateConfig({}).forceRender(); }
});

let logsGrid;
$('#dashboardTabs button[data-bs-target="#logs"]').on('shown.bs.tab',function(){
 if(!logsGrid){
  logsGrid=new gridjs.Grid({
   columns:['کاربر','عملیات','آی‌پی','کشور','شهر','ISP','زمان'],
   server:{
     url:'ajax.php',method:'POST',body:{action:'logs_list'},
     then:data=>data.data.map(l=>[l.user_id,l.action,l.ip_address,l.country,l.city,l.isp,toJalali(l.timestamp)])
   },
   pagination:{limit:10}
  }).render(document.getElementById('logsTable'));
 } else { logsGrid.updateConfig({}).forceRender(); }
});

$('#addUserBtn').click(function(){
 $('#userForm')[0].reset();
 $('#user_id').val('');
 $('.perm').prop('checked',false);
 $('#userModal').modal('show');
});

$('#usersTable').on('click','.edit-user',function(){
 const id=$(this).data('id');
 $.post('ajax.php',{action:'user_get',id:id},function(r){
  if(r.success){
   $('#user_id').val(r.data.id);
   $('#user_username').val(r.data.username);
   $('#user_fullname').val(r.data.full_name);
   $('#user_phone').val(r.data.phone_number);
   $('#user_role').val(r.data.role);
   $('#user_status').val(r.data.status);
   $('.perm').prop('checked',false);
   if(r.data.permissions){ r.data.permissions.split(',').forEach(p=>$('.perm[value="'+p+'"]').prop('checked',true)); }
   $('#user_password').val('');
   $('#userModal').modal('show');
  } else { toastr.error(r.message); }
 },'json');
});

$('#usersTable').on('click','.delete-user',function(){
 const id=$(this).data('id');
 Swal.fire({title:'حذف کاربر؟',icon:'warning',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
  if(res.isConfirmed){
   $.post('ajax.php',{action:'user_delete',id:id},function(r){
    if(r.success){ toastr.success('حذف شد'); usersGrid.updateConfig({}).forceRender(); }
    else { toastr.error(r.message); }
   },'json');
  }
 });
});

$('#userForm').submit(function(e){
 e.preventDefault();
 const perms=$('.perm:checked').map((i,el)=>el.value).get().join(',');
 const data={
  action: $('#user_id').val() ? 'user_update' : 'user_create',
  id: $('#user_id').val(),
  username: $('#user_username').val(),
  password: $('#user_password').val(),
  full_name: $('#user_fullname').val(),
  phone_number: $('#user_phone').val(),
  role: $('#user_role').val(),
  status: $('#user_status').val(),
  permissions: perms
 };
 $.post('ajax.php',data,function(r){
  if(r.success){
   toastr.success('ذخیره شد');
   $('#userModal').modal('hide');
   usersGrid.updateConfig({}).forceRender();
  } else { toastr.error(r.message); }
 },'json');
});

$(function(){
  $.post('ajax.php',{action:'admin_check'},function(r){
    if(r.success && !r.exists){
      $('#adminSetupModal').modal({backdrop:'static',keyboard:false});
      $('#adminSetupModal').modal('show');
    }
  },'json');
});

$('#adminSetupForm').submit(function(e){
  e.preventDefault();
  $.post('ajax.php',{action:'admin_init',username:$('#admin_username').val(),password:$('#admin_password').val()},function(r){
    if(r.success){
      toastr.success('مدیر ایجاد شد، لطفاً دوباره وارد شوید');
      $.post('ajax.php',{action:'logout'},function(){ location.reload(); },'json');
    } else { toastr.error(r.message); }
  },'json');
});

</script>
<?php endif; ?>
</body>
</html>