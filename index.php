<?php
session_start();
$hasLocalConfig = file_exists(__DIR__.'/local_config.secure');
$loggedIn = isset($_SESSION['auth']) && $_SESSION['auth'] === true;
$dbConnected = isset($_SESSION['db']);
$logDbConnected = isset($_SESSION['logdb']);
$permissions = isset($_SESSION['permissions']) ? explode(',', $_SESSION['permissions']) : [];
$canViewSettings = in_array('all',$permissions) || in_array('view_settings',$permissions);
$canEditSlug = in_array('all',$permissions) || in_array('edit_slug',$permissions);
$canViewLogs = in_array('all',$permissions) || in_array('view_logs',$permissions);
$canViewUsers = in_array('all',$permissions) || in_array('view_users',$permissions);
$canViewAssignments = in_array('all',$permissions) || in_array('view_assignments',$permissions);
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4/animate.min.css"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script>toastr.options.positionClass='toast-bottom-left';</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/nprogress@0.2.0/nprogress.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/nprogress@0.2.0/nprogress.css">
<script src="https://cdn.jsdelivr.net/npm/@ckeditor/ckeditor5-build-classic@39.0.1/build/ckeditor.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const toPersianDigits=s=>s.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
$.extend(true,$.fn.dataTable.defaults,{
 language:{paginate:{previous:'قبلی',next:'بعدی'},search:'جستجو:',zeroRecords:'داده‌ای یافت نشد',lengthMenu:'نمایش _MENU_ مورد',info:'نمایش _START_ تا _END_ از _TOTAL_ مورد',infoEmpty:'هیچ موردی یافت نشد'},
 drawCallback:function(){const $t=$(this.api().table().container());$t.find('td,th,a,span,div').contents().filter(function(){return this.nodeType===3;}).each(function(){this.textContent=toPersianDigits(this.textContent);});}
});
</script>
<style>
html,body{height:100%;}
body {font-family:'Vazirmatn', sans-serif; background-color:#f7f7f7; display:flex; flex-direction:column; min-height:100vh;}
#login-box, #db-box {max-width:400px; margin-top:100px;}
.navbar-brand{padding:0 .75rem;margin-right:1rem;}
#profileMenu{margin-left:1rem;}
#pageTimer{margin-right:1rem; font-size:.85rem;}
footer{font-size:.9rem; margin-top:auto;}
#logPanel{max-height:200px; overflow-y:auto;}
.section-card{cursor:pointer;}
#products .dataTables_filter{width:100%;float:none;margin-bottom:1rem;}
#products .dataTables_filter label{width:100%;}
#products .dataTables_filter input{width:100%!important;padding:.75rem 1rem;font-size:1rem;box-shadow:0 0 6px rgba(0,0,0,.15);border:1px solid #ced4da;border-radius:.25rem;}
#logModal .modal-content{height:50vh;}
#logModal .modal-body{display:flex;flex-direction:column;height:calc(50vh - 56px);}
#logModal #userLogTable{flex:1;overflow-y:auto;}
#logModal #userSessionTable{flex:1;overflow-y:auto;}
#products{padding-bottom:3rem;}
.yoast-bad{background:#dc3232;color:#fff;}
.yoast-ok{background:#e7ad1a;color:#fff;}
.yoast-good{background:#7ad03a;color:#fff;}
.yoast-none{background:#999;color:#fff;}
</style>
</head>
<body>

<?php if(!$hasLocalConfig): ?>
<div class="container">
<div id="setupWizard" class="mx-auto" style="max-width:500px;margin-top:60px;">
  <div class="card">
    <div class="card-header text-center">راه‌اندازی سامانه</div>
    <div class="card-body">
      <div id="step1">
        <h5 class="mb-3">اتصال به پایگاه ووکامرس</h5>
        <div class="mb-3"><label class="form-label">نام میزبان</label><input type="text" id="db_host" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">نام پایگاه</label><input type="text" id="db_name" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">نام کاربری</label><input type="text" id="db_user" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">رمز عبور</label><input type="password" id="db_pass" class="form-control" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label">پیشوند جداول</label><input type="text" id="db_prefix" class="form-control" value="wp_" autocomplete="off"></div>
        <div class="d-flex justify-content-end">
          <button id="setup-next1" class="btn btn-primary">بعدی</button>
        </div>
      </div>
      <div id="step2" class="d-none">
        <h5 class="mb-3">اتصال پایگاه داده سامانه</h5>
        <div class="mb-3"><label class="form-label">نام میزبان</label><input type="text" id="local_host" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">نام پایگاه</label><input type="text" id="local_name" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">نام کاربری</label><input type="text" id="local_user" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">رمز عبور</label><input type="password" id="local_pass" class="form-control" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label">پیشوند جداول</label><input type="text" id="local_prefix" class="form-control" value="msw_" autocomplete="off"></div>
        <div class="d-flex justify-content-between">
          <button id="setup-back2" class="btn btn-secondary">قبلی</button>
          <button id="setup-next2" class="btn btn-primary">بعدی</button>
        </div>
      </div>
      <div id="step3" class="d-none">
        <h5 class="mb-3">ایجاد مدیر سیستم</h5>
        <div class="mb-3"><label class="form-label">نام کاربری</label><input type="text" id="admin_username" class="form-control" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">رمز عبور</label><input type="password" id="admin_password" class="form-control" autocomplete="new-password"></div>
        <div class="d-flex justify-content-between">
          <button id="setup-back3" class="btn btn-secondary">قبلی</button>
          <button id="setup-finish" class="btn btn-success">اتمام</button>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<script>
$(function(){
  $('#setup-next1').click(function(){
    $.post('ajax.php',{action:'db_connect',host:$('#db_host').val(),name:$('#db_name').val(),user:$('#db_user').val(),pass:$('#db_pass').val(),prefix:$('#db_prefix').val()},function(r){
      if(r.success){
        toastr.success('اتصال پایگاه ووکامرس برقرار شد');
        $('#step1').addClass('d-none');
        $('#step2').removeClass('d-none');
      }
      else{
        Swal.fire('خطا',r.message,'error');
      }
    },'json');
  });
  $('#setup-back2').click(function(){
    $('#step2').addClass('d-none');
    $('#step1').removeClass('d-none');
  });
  $('#setup-next2').click(function(){
    $.post('ajax.php',{action:'local_db_connect',host:$('#local_host').val(),name:$('#local_name').val(),user:$('#local_user').val(),pass:$('#local_pass').val(),prefix:$('#local_prefix').val()},function(r){
      if(r.success){
        toastr.success('اتصال پایگاه داده سامانه برقرار شد');
        $('#step2').addClass('d-none');
        $('#step3').removeClass('d-none');
      }
      else{
        Swal.fire('خطا',r.message,'error');
      }
    },'json');
  });
  $('#setup-back3').click(function(){
    $('#step3').addClass('d-none');
    $('#step2').removeClass('d-none');
  });
  $('#setup-finish').click(function(){
    $.post('ajax.php',{action:'admin_init',username:$('#admin_username').val(),password:$('#admin_password').val()},function(r){
      if(r.success){
        Swal.fire('موفق','مدیر ایجاد شد، لطفاً وارد شوید','success').then(()=>{window.location='index.php';});
      }else{
        Swal.fire('خطا',r.message,'error');
      }
    },'json');
  });
});
</script>
<?php elseif(!$loggedIn): ?>
<div class="container">
<div id="login-box" class="mx-auto animate__animated animate__fadeInDown">
  <div class="card shadow-sm">
    <div class="card-body text-center">
      <form id="loginForm" autocomplete="on">
        <i class="fa-solid fa-lock fa-3x mb-3"></i>
        <div class="mb-3">
          <input type="text" id="username" name="username" class="form-control text-center" placeholder="نام کاربری" autocomplete="username">
        </div>
        <div class="mb-3">
          <input type="password" id="password" name="password" class="form-control text-center" placeholder="رمز عبور" autocomplete="current-password">
        </div>
        <button id="login-btn" class="btn btn-dark w-100" type="submit">ورود</button>
      </form>
    </div>
  </div>
</div>
</div>
<script>
$('#loginForm').submit(function(e){
   e.preventDefault();
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
        <input type="text" id="db_host" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">نام پایگاه</label>
        <input type="text" id="db_name" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" id="db_user" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">رمز عبور</label>
        <input type="password" id="db_pass" class="form-control" autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label">پیشوند جداول</label>
        <input type="text" id="db_prefix" class="form-control" value="wp_" autocomplete="off">
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
        toastr.success('اتصال پایگاه ووکامرس برقرار شد');
        setTimeout(()=>location.reload(),800);
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
        <input type="text" id="local_host" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">نام پایگاه</label>
        <input type="text" id="local_name" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" id="local_user" class="form-control" autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">رمز عبور</label>
        <input type="password" id="local_pass" class="form-control" autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label">پیشوند جداول</label>
        <input type="text" id="local_prefix" class="form-control" value="msw_" autocomplete="off">
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
    if(res.success){
      toastr.success('اتصال پایگاه داده سامانه برقرار شد');
      setTimeout(()=>location.reload(),800);
    }else{
      Swal.fire('خطا',res.message,'error');
    }
  },'json');
});
</script>
<?php else: ?>
<?php $displayName = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : $_SESSION['username']; ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark w-100">
  <a class="navbar-brand ms-3" href="#"><i class="fa-solid fa-screwdriver-wrench me-2"></i>بخش مدیریت</a>
  <span id="pageTimer" class="text-light"></span>
  <div class="dropdown ms-auto me-3 d-flex align-items-center">
    <span class="text-light me-2"><?=htmlspecialchars($displayName)?></span>
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
<div class="container-fluid mt-4 pb-5">
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
  <?php if($canViewUsers): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#users" type="button">کاربران</button>
  </li>
  <?php endif; ?>
  <?php if($canViewAssignments): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#assignments" type="button">تخصیص محصولات</button>
  </li>
  <?php endif; ?>
  <?php if($canViewSettings): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#searchConsole" type="button">سرچ کنسول</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#settings" type="button">تنظیمات</button>
  </li>
  <?php endif; ?>
</ul>
<div class="tab-content mt-4">
<div class="tab-pane fade show active p-3" id="products">
<table id="productsTable" class="table table-striped table-bordered" style="width:100%">
  <thead>
    <tr>
      <th>ID</th>
      <th>تصویر</th>
      <th>نام</th>
      <th>قیمت</th>
      <th>انبارداری</th>
      <th style="width:80px">نمره سئو</th>
      <th>ویرایش</th>
      <th>تاریخچه</th>
      <th>نمایش</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
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
          <button class="btn btn-primary" id="bulkSeoKeywords">کپی نام محصول در کلیدواژه کانونی</button>
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
    <table id="usersTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
  </div>
  <div class="tab-pane fade p-3" id="assignments">
    <table id="assignUsersTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
  </div>
  <?php if($canViewSettings): ?>
  <div class="tab-pane fade p-3" id="searchConsole">
    <ul class="nav nav-tabs mb-3" id="scNav">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#scKeywords" type="button">کلمات کلیدی</button></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="scKeywords">
        <div class="row text-center mb-3" id="scSummary">
          <div class="col-6 col-md-3 mb-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">کل کلیک‌ها</div><div class="h4 mb-0" id="scClicks">0</div></div></div></div>
          <div class="col-6 col-md-3 mb-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">کل نمایش‌ها</div><div class="h4 mb-0" id="scImpressions">0</div></div></div></div>
          <div class="col-6 col-md-3 mb-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">میانگین CTR</div><div class="h4 mb-0" id="scCtr">0%</div></div></div></div>
          <div class="col-6 col-md-3 mb-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">میانگین رتبه</div><div class="h4 mb-0" id="scPosition">0</div></div></div></div>
        </div>
        <div class="row g-2 mb-3 align-items-end">
          <div class="col-md-2"><label class="form-label">از تاریخ</label><input type="date" id="kwFrom" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">تا تاریخ</label><input type="date" id="kwTo" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">نوع</label><select id="kwDimension" class="form-select"><option value="query">کوئری</option><option value="page">صفحه</option><option value="country">کشور</option><option value="device">دستگاه</option><option value="searchAppearance">نوع نمایش</option></select></div>
          <div class="col-md-2"><label class="form-label">جستجو</label><input type="text" id="kwQuery" class="form-control" placeholder="جستجو..."></div>
          <div class="col-md-2"><label class="form-label">دستگاه</label><select id="kwDevice" class="form-select"><option value="">همه</option><option value="DESKTOP">دسکتاپ</option><option value="MOBILE">موبایل</option><option value="TABLET">تبلت</option></select></div>
          <div class="col-md-1"><label class="form-label">کشور</label><input type="text" id="kwCountry" class="form-control" placeholder="IR"></div>
          <div class="col-md-1"><button class="btn btn-primary w-100" id="filterKeywords">اعمال</button></div>
        </div>
        <canvas id="scChart" height="120" class="mb-3"></canvas>
        <table id="searchConsoleTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </div>
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
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#chatgptModal">
          <div class="card-body">
            <i class="fa fa-comments fa-2x mb-2"></i>
            <div>تنظیمات ChatGPT</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#roleModal">
          <div class="card-body">
            <i class="fa fa-user-shield fa-2x mb-2"></i>
            <div>نقش‌ها</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#dnsModal">
          <div class="card-body">
            <i class="fa fa-shield-halved fa-2x mb-2"></i>
            <div>DNS تحریم‌شکن</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#processModal">
          <div class="card-body">
            <i class="fa fa-sync fa-2x mb-2"></i>
            <div>فرآیندهای دوره‌ای</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#internalLinksModal">
          <div class="card-body">
            <i class="fa fa-link fa-2x mb-2"></i>
            <div>لینک‌های داخلی</div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card section-card text-center" data-bs-toggle="modal" data-bs-target="#externalLinksModal">
          <div class="card-body">
            <i class="fa fa-arrow-up-right-from-square fa-2x mb-2"></i>
            <div>لینک‌های خارجی</div>
          </div>
        </div>
      </div>
      <?php if($canViewLogs): ?>
      <div class="col-md-3">
        <div class="card section-card text-center" id="openLogsCard">
          <div class="card-body">
            <i class="fa fa-list fa-2x mb-2"></i>
            <div>لاگ ورود و خروج</div>
          </div>
        </div>
      </div>
      <?php endif; ?>
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
      <input type="text" id="cfg_host" class="form-control" autocomplete="off">
     </div>
     <div class="mb-3">
      <label class="form-label">نام پایگاه</label>
      <input type="text" id="cfg_name" class="form-control" autocomplete="off">
     </div>
     <div class="mb-3">
      <label class="form-label">نام کاربری</label>
      <input type="text" id="cfg_user" class="form-control" autocomplete="off">
     </div>
     <div class="mb-3">
      <label class="form-label">رمز عبور</label>
      <input type="password" id="cfg_pass" class="form-control" autocomplete="new-password">
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
   <div class="mb-3">
    <label class="form-label">Client ID سرچ کنسول</label>
    <input type="text" id="scClientId" class="form-control">
   </div>
   <div class="mb-3">
   <label class="form-label">Client Secret سرچ کنسول</label>
   <input type="text" id="scClientSecret" class="form-control">
  </div>
  <div class="mb-3">
    <label class="form-label">آدرس سایت سرچ کنسول</label>
    <input type="text" id="scSite" class="form-control" placeholder="https://example.com/">
  </div>
  <div class="mb-3">
    <label class="form-label">Refresh Token سرچ کنسول</label>
    <input type="text" id="scRefresh" class="form-control">
  </div>
  </div>
  <div class="modal-footer">
   <button id="saveApiSettings" class="btn btn-primary" type="button">ذخیره</button>
  </div>
</div>
</div>
</div>

<div class="modal fade" id="chatgptModal" tabindex="-1">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">تنظیمات ChatGPT</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="mb-3">
     <label class="form-label">API Key</label>
     <input type="password" id="chatgptApiKey" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">Model</label>
     <input type="text" id="chatgptModel" class="form-control" placeholder="gpt-4">
    </div>
    <div class="mb-3">
     <label class="form-label">Temperature</label>
     <input type="number" step="0.1" id="chatgptTemperature" class="form-control">
    </div>
    <div class="mb-3">
     <label class="form-label">Max Tokens</label>
     <input type="number" id="chatgptMaxTokens" class="form-control">
    </div>
    <div id="chatgptStatus" class="small mb-2"></div>
    <pre id="chatgptLog" class="small bg-light p-2"></pre>
   </div>
   <div class="modal-footer">
    <button id="testChatgpt" type="button" class="btn btn-secondary">تست اتصال</button>
    <button id="saveChatgpt" type="button" class="btn btn-primary">ذخیره</button>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="dnsModal" tabindex="-1">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">DNS تحریم‌شکن</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="mb-3">
     <label class="form-label">DNS ها (با کاما جدا کنید)</label>
     <input type="text" id="dnsServers" class="form-control" placeholder="8.8.8.8,1.1.1.1">
    </div>
    <div id="dnsTestResult" class="small"></div>
   </div>
   <div class="modal-footer">
    <button id="testDns" type="button" class="btn btn-secondary">تست</button>
    <button id="saveDns" type="button" class="btn btn-primary">ذخیره</button>
   </div>
</div>
</div>
</div>

<div class="modal fade" id="processModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">فرآیندهای دوره‌ای</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <table id="processesTable" class="table table-striped">
     <thead>
      <tr><th>فرایند</th><th>بازه (ساعت)</th><th>فعال</th><th>آخرین اجرا</th><th>اجرا</th></tr>
     </thead>
     <tbody></tbody>
    </table>
   </div>
   <div class="modal-footer">
    <button id="saveProcesses" type="button" class="btn btn-primary">ذخیره</button>
   </div>
</div>
</div>
</div>

<div class="modal fade" id="internalLinksModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">لینک‌های داخلی</h5>
    <button class="btn btn-sm btn-outline-secondary me-2" id="syncInternalLinks">همگام‌سازی</button>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <table id="internalLinksTable" class="table table-striped w-100"><thead><tr><th>متن</th><th>آدرس</th><th>عملیات</th></tr></thead><tbody></tbody></table>
    <input type="hidden" id="il_id"><input type="hidden" id="il_category">
    <div class="row g-2 mt-3">
      <div class="col-md-6"><input type="text" id="il_url" class="form-control" placeholder="آدرس"></div>
      <div class="col-md-6"><input type="text" id="il_title" class="form-control" placeholder="متن"></div>
    </div>
    <div class="mt-3 text-end"><button class="btn btn-primary" id="saveInternalLink">ذخیره</button></div>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="externalLinksModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header"><h5 class="modal-title">لینک‌های خارجی</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
    <table id="externalLinksTable" class="table table-striped w-100"><thead><tr><th>آدرس</th><th>عنوان</th><th>عملیات</th></tr></thead><tbody></tbody></table>
    <input type="hidden" id="el_id">
    <div class="row g-2 mt-3">
      <div class="col-md-6"><input type="text" id="el_url" class="form-control" placeholder="آدرس"></div>
      <div class="col-md-6"><input type="text" id="el_title" class="form-control" placeholder="عنوان"></div>
    </div>
    <div class="mt-3 text-end"><button class="btn btn-primary" id="saveExternalLink">ذخیره</button></div>
   </div>
  </div>
 </div>
</div>

<div class="modal fade" id="roleModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">نقش‌ها</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <form id="roleForm" class="mb-3">
      <input type="hidden" id="role_id">
      <div class="mb-3">
        <label class="form-label">نام نقش</label>
        <input type="text" id="role_name" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">دسترسی‌ها</label>
        <div class="form-check">
          <input class="form-check-input rperm" type="checkbox" value="view_settings" id="rperm_settings">
          <label class="form-check-label" for="rperm_settings">مشاهده تنظیمات</label>
        </div>
        <div class="form-check">
          <input class="form-check-input rperm" type="checkbox" value="edit_slug" id="rperm_slug">
          <label class="form-check-label" for="rperm_slug">ویرایش نامک</label>
        </div>
        <div class="form-check">
          <input class="form-check-input rperm" type="checkbox" value="view_logs" id="rperm_logs">
          <label class="form-check-label" for="rperm_logs">مشاهده لاگ‌ها</label>
        </div>
        <div class="form-check">
          <input class="form-check-input rperm" type="checkbox" value="view_users" id="rperm_users">
          <label class="form-check-label" for="rperm_users">مشاهده تب کاربران</label>
        </div>
        <div class="form-check">
          <input class="form-check-input rperm" type="checkbox" value="view_assignments" id="rperm_assign">
          <label class="form-check-label" for="rperm_assign">مشاهده تب تخصیص محصولات</label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">ذخیره نقش</button>
    </form>
    <table id="rolesTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
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
     <input type="text" id="user_username" class="form-control" required autocomplete="off">
    </div>
    <div class="mb-3">
     <label class="form-label">رمز عبور</label>
     <input type="password" id="user_password" class="form-control" autocomplete="new-password">
    </div>
    <div class="mb-3">
     <label class="form-label">نام کامل</label>
     <input type="text" id="user_fullname" class="form-control" autocomplete="off">
    </div>
    <div class="mb-3">
     <label class="form-label">شماره تلفن</label>
     <input type="text" id="user_phone" class="form-control" autocomplete="off">
    </div>
    <div class="mb-3">
     <label class="form-label">نقش</label>
     <select id="user_role" class="form-select"></select>
    </div>
    <div class="mb-3">
     <label class="form-label">وضعیت</label>
     <select id="user_status" class="form-select">
      <option value="active" selected>فعال</option>
      <option value="inactive">غیرفعال</option>
      <option value="banned">مسدود</option>
     </select>
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
    <table id="userLogTable" class="table table-striped mb-3 w-100"><thead><tr></tr></thead><tbody></tbody></table>
    <hr>
    <table id="userSessionTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
   </div>
  </div>
</div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1">
 <div class="modal-dialog modal-xl">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">تاریخچه تغییرات محصول</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <div class="row mb-3">
      <div class="col"><input type="text" id="historyUser" class="form-control" placeholder="کاربر"></div>
      <div class="col"><input type="date" id="historyFrom" class="form-control"></div>
      <div class="col"><input type="date" id="historyTo" class="form-control"></div>
      <div class="col"><button class="btn btn-secondary w-100" id="filterHistory">فیلتر</button></div>
    </div>
    <table id="historyTable" class="table table-striped w-100">
      <thead><tr><th>نسخه</th><th>کاربر</th><th>زمان</th><th>قدیم</th><th>جدید</th><th>عملیات</th></tr></thead>
      <tbody></tbody>
    </table>
   </div>
  </div>
</div>
</div>

<?php if($canViewLogs): ?>
<div class="modal fade" id="logsModal" tabindex="-1">
 <div class="modal-dialog modal-lg">
  <div class="modal-content">
   <div class="modal-header">
    <h5 class="modal-title">لاگ ورود و خروج</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
   </div>
   <div class="modal-body">
    <table id="logsTable" class="table table-striped mb-3 w-100"><thead><tr></tr></thead><tbody></tbody></table>
    <hr>
    <table id="sessionsTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
   </div>
  </div>
 </div>
</div>
<?php endif; ?>

<div class="modal fade" id="assignModal" tabindex="-1">
 <div class="modal-dialog">
  <div class="modal-content">
   <div class="modal-header">
   <h5 class="modal-title">تخصیص محصولات به <span id="assignUser"></span></h5>
   <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
   <input type="hidden" id="assignUserId">
   <div class="mb-3">
     <label class="form-label">حالت تخصیص</label>
     <div>
       <div class="form-check form-check-inline">
         <input class="form-check-input" type="radio" name="assignMode" id="modeQuota" value="quota">
         <label class="form-check-label" for="modeQuota">سهمیه‌ای</label>
       </div>
       <div class="form-check form-check-inline">
         <input class="form-check-input" type="radio" name="assignMode" id="modeCategory" value="category">
         <label class="form-check-label" for="modeCategory">دسته‌بندی</label>
       </div>
       <div class="form-check form-check-inline">
         <input class="form-check-input" type="radio" name="assignMode" id="modeManual" value="manual">
         <label class="form-check-label" for="modeManual">انتخاب دستی</label>
       </div>
     </div>
     <div class="form-check mt-2">
       <input class="form-check-input" type="checkbox" id="confirmMode">
       <label class="form-check-label" for="confirmMode">تأیید فعال‌سازی</label>
     </div>
     <button class="btn btn-sm btn-primary mt-2" id="btnSaveMode">ثبت حالت</button>
   </div>
   <ul class="nav nav-tabs" id="assignTabs" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#assignQuota" type="button">سهمیه‌ای</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#assignCategory" type="button">دسته‌بندی</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#assignManual" type="button">انتخاب دستی</button></li>
    </ul>
    <div class="tab-content p-3 border border-top-0">
      <div class="tab-pane fade show active" id="assignQuota">
        <div class="mb-2">تعداد کل محصولات: <span id="totalProducts">0</span></div>
        <div class="mb-3">
          <label class="form-label">تعداد</label>
          <input type="number" id="assignQuotaCount" class="form-control">
        </div>
        <button class="btn btn-primary" id="btnAssignQuota">تخصیص</button>
      </div>
      <div class="tab-pane fade" id="assignCategory">
        <div class="mb-3">
          <label class="form-label">دسته‌بندی</label>
          <select id="assignCategorySelect" class="form-select"></select>
        </div>
        <button class="btn btn-primary" id="btnAssignCategory">تخصیص</button>
      </div>
      <div class="tab-pane fade" id="assignManual">
      <div class="mb-3">
        <label class="form-label">محصولات</label>
        <select id="assignManualSelect" multiple class="form-select"></select>
      </div>
      <button class="btn btn-primary" id="btnAssignManual">تخصیص</button>
    </div>
    </div>
    <div class="mt-3">
      <h6>محصولات تخصیص‌یافته</h6>
      <table id="assignedProductsTable" class="table table-striped w-100"><thead><tr></tr></thead><tbody></tbody></table>
    </div>
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
        <div id="seo_suggestions" class="mt-2 d-none">
          <div class="mb-1"><strong>پیشنهاد عنوان:</strong> <span id="seo_sug_title"></span> <button type="button" class="btn btn-sm btn-outline-primary" id="apply_title">اعمال</button></div>
          <div><strong>پیشنهاد توضیحات:</strong> <span id="seo_sug_meta"></span> <button type="button" class="btn btn-sm btn-outline-primary" id="apply_meta">اعمال</button></div>
        </div>
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
     <input type="text" id="admin_username" class="form-control" required autocomplete="off">
    </div>
    <div class="mb-3">
     <label class="form-label">رمز عبور</label>
     <input type="password" id="admin_password" class="form-control" required autocomplete="new-password">
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
const myUsername = <?= json_encode($displayName) ?>;
let licenses={};
let descEditor, promptEditor;
$.ajaxSetup({xhrFields:{withCredentials:true}});
$.post('ajax.php',{action:'load_licenses'},function(res){
 if(res.success) licenses=res.data;
 if(window.ClassicEditor){
   ClassicEditor.create(document.querySelector('#prod_desc'),{
     language:'fa',
     licenseKey: licenses.ckeditor || ''
   }).then(ed=>{ descEditor=ed; }).catch(()=>{});
 }
},'json');

$('#descTabs button[data-bs-target="#desc-html"]').on('shown.bs.tab',function(){
  if(descEditor){ $('#prod_desc_html').val(descEditor.getData()); }
});
$('#descTabs button[data-bs-target="#desc-editor"]').on('shown.bs.tab',function(){
  if(descEditor){ descEditor.setData($('#prod_desc_html').val()); }
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

$('#btnSaveMode').click(function(){
  const uid=$('#assignUserId').val();
  const mode=$('input[name="assignMode"]:checked').val();
  if(!$('#confirmMode').is(':checked')){ toastr.error('تأیید فعال‌سازی لازم است'); return; }
  if(!mode){ toastr.error('یک حالت را انتخاب کنید'); return; }
  $.post('ajax.php',{action:'set_assign_mode',user_id:uid,mode:mode},function(r){
    if(r.success){ toastr.success('حالت ذخیره شد'); setModeUI(mode); loadAssignUsers(); }
    else{ toastr.error('ذخیره نشد'); }
  },'json');
});

$('#assignedProductsTable').on('click','.rm-assign',function(){
  const pid=$(this).data('id');
  const uid=$('#assignUserId').val();
  $.post('ajax.php',{action:'remove_assignment',user_id:uid,product_id:pid},function(r){
    if(r.success){ toastr.success('حذف شد'); loadAssignedProducts(uid); loadAssignUsers(); }
    else{ toastr.error('حذف نشد'); }
  },'json');
});

$('#assignedProductsTable').on('click','.transfer-assign',function(){
  const pid=$(this).data('id');
  const uid=$('#assignUserId').val();
  $.post('ajax.php',{action:'users_list'},function(res){
    if(res.success){
      let opts='';
      res.data.filter(u=>u.id!=uid).forEach(u=>{ opts+=`<option value='${u.id}'>${u.username}</option>`; });
      Swal.fire({title:'انتقال به کاربر',html:`<select id="transferUser" class="form-select">${opts}</select>`,showCancelButton:true,confirmButtonText:'انتقال',preConfirm:()=>$('#transferUser').val()}).then(ch=>{
        if(ch.value){
          $.post('ajax.php',{action:'transfer_assignment',product_id:pid,target_user:ch.value},function(r){
            if(r.success){ toastr.success('انتقال انجام شد'); loadAssignedProducts(uid); loadAssignUsers(); }
            else{ toastr.error(r.message); }
          },'json');
        }
      });
    }
  },'json');
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
    if(promptEditor){
      $.post('ajax.php',{action:'load_prompt_template'},function(res){ if(res.success){ promptEditor.setData(res.template); } },'json');
    }
  };
  if(!promptEditor){
    if(window.ClassicEditor){
      ClassicEditor.create(document.querySelector('#promptTemplate'),{language:'fa',licenseKey: licenses.ckeditor || ''}).then(ed=>{promptEditor=ed; init();}).catch(()=>{});
    }
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
  $.post('ajax.php',{action:'load_api_settings'},function(res){
    if(res.success){
      $('#ipifyKey').val(res.ipify);
      $('#scClientId').val(res.sc_client_id);
      $('#scClientSecret').val(res.sc_client_secret);
      $('#scSite').val(res.sc_site);
      $('#scRefresh').val(res.sc_refresh_token);
    }
  },'json');
});
$('#saveApiSettings').click(function(){
  $.post('ajax.php',{
    action:'save_api_settings',
    ipify:$('#ipifyKey').val(),
    sc_client_id:$('#scClientId').val(),
    sc_client_secret:$('#scClientSecret').val(),
    sc_site:$('#scSite').val(),
    sc_refresh_token:$('#scRefresh').val()
  },function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#apiModal').modal('hide'); }
    else{ toastr.error('خطا'); }
  },'json');
});

$('#chatgptModal').on('shown.bs.modal',function(){
  $.post('ajax.php',{action:'get_chatgpt_settings'},function(res){
    if(res.success){
      $('#chatgptApiKey').val(res.config.api_key||'');
      $('#chatgptModel').val(res.config.model||'');
      $('#chatgptTemperature').val(res.config.temperature||'');
      $('#chatgptMaxTokens').val(res.config.max_tokens||'');
      $('#chatgptStatus').text('');
      $('#chatgptLog').text('');
    }
  },'json');
});
$('#saveChatgpt').click(function(){
  $.post('ajax.php',{
    action:'save_chatgpt_settings',
    api_key:$('#chatgptApiKey').val(),
    model:$('#chatgptModel').val(),
    temperature:$('#chatgptTemperature').val(),
    max_tokens:$('#chatgptMaxTokens').val()
  },function(res){
    if(res.success){
      toastr.success('ذخیره شد');
      $.post('ajax.php',{action:'test_chatgpt'},function(t){
        $('#chatgptStatus').text(t.message||'');
        if(t.steps){
          $('#chatgptLog').text(t.steps.map(s=>'['+s.flag+'] '+s.message).join('\n'));
        }
      },'json');
    } else {
      toastr.error(res.message||'خطا');
    }
  },'json');
});
$('#testChatgpt').click(function(){
  $('#chatgptStatus').text('در حال بررسی...');
  $('#chatgptLog').text('');
  $.post('ajax.php',{action:'test_chatgpt'},function(res){
    $('#chatgptStatus').text(res.message||'');
    if(res.steps){
      $('#chatgptLog').text(res.steps.map(s=>'['+s.flag+'] '+s.message).join('\n'));
    }
  },'json');
});

$('#dnsModal').on('shown.bs.modal',function(){
  $.post('ajax.php',{action:'get_dns_settings'},function(res){
    if(res.success){ $('#dnsServers').val(res.dns.join(',')); }
  },'json');
});
$('#saveDns').click(function(){
  $.post('ajax.php',{action:'save_dns_settings',dns:$('#dnsServers').val()},function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#dnsModal').modal('hide'); }
    else{ toastr.error('خطا'); }
  },'json');
});
$('#testDns').click(function(){
  $('#dnsTestResult').text('در حال تست...');
  $.post('ajax.php',{action:'test_dns'},function(res){
    if(res.success){
      var msg='IP: '+res.ip+' کشور: '+res.country;
      if(res.warning) msg+=' - '+res.warning;
      $('#dnsTestResult').text(msg);
    } else {
      $('#dnsTestResult').text(res.message||'خطا');
    }
  },'json');
});

$('#processModal').on('shown.bs.modal',function(){
  if(!processesTable) initProcesses();
  loadProcesses();
});

$('#saveProcesses').click(function(){
  let data=[];
  $('#processesTable tbody tr').each(function(){
    const row=processesTable.row(this).data();
    const interval=$(this).find('.interval').val();
    const active=$(this).find('.active').prop('checked')?1:0;
    data.push({name:row.name,interval:interval,active:active});
  });
  $.post('ajax.php',{action:'save_processes',data:JSON.stringify(data)},function(res){
    if(res.success){ toastr.success('ذخیره شد'); loadProcesses(); }
    else{ toastr.error(res.message||'خطا'); }
  },'json');
});

$('#processesTable').on('click','.run',function(){
  const name=$(this).data('name');
  $.post('ajax.php',{action:'run_process',name:name},function(res){
    if(res.steps){ res.steps.forEach(s=>logStep('ProcessManager: '+s)); }
    if(res.success){ toastr.success('اجرا شد'); loadProcesses(); }
    else{ toastr.error(res.message||'خطا'); }
  },'json');
});

$(function(){
  const params=new URLSearchParams(window.location.search);
  if(params.has('code') && params.get('scope') && params.get('scope').includes('webmasters.readonly')){
    NProgress.start();
    $.post('ajax.php',{
      action:'sc_exchange_code',
      code:params.get('code'),
      redirect:location.origin+location.pathname
    },function(r){
      NProgress.done();
      if(r.success){ toastr.success('توکن سرچ کنسول ذخیره شد'); }
      else{ toastr.error(r.message||'خطا در دریافت توکن'); }
      history.replaceState({},document.title,location.pathname);
    },'json');
  }
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


function log(msg){
 const ts=new Date().toISOString();
 const line=`[${ts}] ${msg}`;
 $('#logPanel').text($('#logPanel').text()+line+"\n");
 $('#logPanel').scrollTop($('#logPanel')[0].scrollHeight);
 console.log(line);
}
function logStep(msg){ log(msg); }
$('#toggleLog').click(()=>$('#logPanel').toggleClass('d-none'));
$('#copyLog').click(()=>{ navigator.clipboard.writeText($('#logPanel').text()); toastr.info('کپی شد'); });
$(document).ajaxStart(()=>NProgress.start());
$(document).ajaxStop(()=>NProgress.done());
// DataTables tables
let productsTable, usersTable, logsTable, userLogDataTable, assignUsersTable, assignedProductsTable, searchConsoleTable, scChart, sessionsTable, userSessionDataTable, rolesTable, processesTable;
let productsInit=false, usersInit=false, assignmentsInit=false, logsInit=false, scKeywordsInit=false;
function initProducts(){
  productsTable=$('#productsTable').DataTable({
    columns:[
      {data:'id', visible:false},
      {data:'image', render:data=>`<img src="${data}" width="50" height="50" loading="lazy">`},
      {data:'name'},
      {data:'price'},
      {data:'stock', render:data=>`<span class='${data=="موجود"?'text-success':'text-danger'}'>${data}</span>`},
      {data:'score', render:data=>{
         let cls='yoast-none',txt='-';
         if(data!==null){txt=data; if(data>=70)cls='yoast-good'; else if(data>=40)cls='yoast-ok'; else cls='yoast-bad';}
         return `<span class='badge ${cls}'>${txt}</span>`;
      }},
      {data:null, orderable:false, render:row=>`<button class='btn btn-sm btn-primary edit' data-id='${row.id}'>ویرایش</button>`},
      {data:null, orderable:false, render:row=>`<button class='btn btn-sm btn-info history' data-id='${row.id}'>تاریخچه</button>`},
      {data:'link', orderable:false, render:data=>`<a class='btn btn-sm btn-outline-secondary' target='_blank' href='${data}'>نمایش</a>`}
    ],
    searching:true,
    paging:true,
    lengthChange:true,
    pageLength:10,
    lengthMenu:[[10,25,50,-1],[10,25,50,'همه']],
    info:false,
    columnDefs:[
      {targets:'_all', className:'text-center'},
      {targets:4, width:'90px'},
      {targets:5, width:'80px'},
      {targets:[6,7,8], width:'90px'}
    ],
    language:{search:'',searchPlaceholder:'جستجو...'},
    dom:"<'row'<'col-sm-8'f><'col-sm-4'l>>t<'row'<'col-sm-6'i><'col-sm-6'p>>"
  });
  $('#productsTable_filter input').addClass('form-control form-control-lg shadow-sm').css('width','80%');
  loadProducts();
}
function loadProducts(){
 const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
 NProgress.start();
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=list_products'})
  .then(r=>r.json()).then(r=>{
    if(r.success){
      productsTable.clear();
      productsTable.rows.add(r.data).draw();
      const needInit = r.data.length>0 && r.data.every(p=>p.score===null);
      if(needInit){
        $.post('ajax.php',{action:'run_process',name:'seo_score'},function(res){
          if(res.steps){ res.steps.forEach(s=>logStep('ProcessManager: '+s)); }
          toastr.success('محاسبه نمره سئو انجام شد');
          loadProducts();
        },'json');
      }
    }else{ toastr.error(r.message); }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});
}

let historyTable;
function initHistoryTable(){
  historyTable=$('#historyTable').DataTable({
    columns:[
      {data:'version'},
      {data:'username'},
      {data:'changed_at'},
      {data:'old_content', render:d=>d?d.substring(0,30)+'...':'-'},
      {data:'new_content', render:d=>d?d.substring(0,30)+'...':''},
      {data:null, orderable:false, render:row=>`<button class='btn btn-sm btn-warning revert' data-version='${row.version}'>بازگردانی</button>`}
    ],
    searching:true,
    paging:true,
    info:false,
    language:{search:'',searchPlaceholder:'جستجو...'},
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  $('#historyTable_filter input').addClass('form-control');
}

function initProcesses(){
  processesTable=$('#processesTable').DataTable({
    columns:[
      {data:'label'},
      {data:'interval', render:d=>`<input type='number' class='form-control form-control-sm interval' value='${d}'>`},
      {data:'active', render:d=>`<input type='checkbox' class='form-check-input active' ${d==1?'checked':''}>`},
      {data:'last_run', render:d=>d?d:'-'},
      {data:null, orderable:false, render:row=>`<button class='btn btn-sm btn-secondary run' data-name='${row.name}'>اجرا</button>`}
    ],
    searching:false,
    paging:false,
    info:false,
    ordering:false,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
}

function loadProcesses(){
 const toast=toastr.info('لطفاً صبر کنید',{timeOut:0,extendedTimeOut:0});
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_processes'})
  .then(r=>r.json()).then(r=>{
    if(r.steps){
      r.steps.forEach(s=>logStep('ProcessManager: '+s));
      if(r.steps.some(s=>s.includes('مقداردهی'))){ toastr.info('فرایندهای پیش‌فرض ثبت شدند'); }
    }
    if(r.success){
      const rows=r.data.map(p=>({name:p.name,label:p.name==='seo_score'?'محاسبه نمره سئو':'دریافت داده گوگل',interval:p.interval_hours,active:p.active,last_run:p.last_run}));
      processesTable.clear();
      processesTable.rows.add(rows).draw();
    }else{ toastr.error(r.message); }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);});
}
function loadHistory(pid){
  if(!historyTable) initHistoryTable();
  const user=$('#historyUser').val()||'';
  const from=$('#historyFrom').val()||'';
  const to=$('#historyTo').val()||'';
  const params=`action=get_content_history&product_id=${pid}&user=${encodeURIComponent(user)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
  fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params})
    .then(r=>r.json()).then(r=>{
      if(r.success){ historyTable.clear(); historyTable.rows.add(r.data).draw(); }
      else { toastr.error(r.message); }
    });
}
$('#filterHistory').click(()=>{ const pid=$('#historyModal').data('pid'); loadHistory(pid);});
function loadServerSeo(id){
  fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=analyze_product_seo&id='+id})
    .then(r=>r.json()).then(r=>{
      if(r.success){
        const score=r.data.score;
        let badge='bg-danger';
        if(score>=70) badge='bg-success'; else if(score>=40) badge='bg-warning';
        $('#seo_score').removeClass().addClass('badge '+badge).text(score);
        const list=$('#seo_feedback').empty();
        r.data.details.forEach(d=>{
          const cls=d.status=='ok'?'text-success':(d.status=='warn'?'text-warning':'text-danger');
          list.append(`<li class="${cls}">${d.message}</li>`);
        });
        $('#seo_sug_title').text(r.suggestions.title);
        $('#seo_sug_meta').text(r.suggestions.meta);
        $('#seo_suggestions').removeClass('d-none');
      } else {
        toastr.error(r.message);
      }
    });
}

let internalLinksTable, externalLinksTable;
function initInternalLinks(){
  internalLinksTable=$('#internalLinksTable').DataTable({
    columns:[
      {data:'title'},
      {data:'url'},
      {data:null,orderable:false,render:row=>`<button class='btn btn-sm btn-warning il-edit' data-id='${row.id}'>ویرایش</button>`}
    ],
    searching:false,paging:false,info:false,columnDefs:[{targets:'_all',className:'text-center'}]
  });
  loadInternalLinks();
}
function loadInternalLinks(){
  $.post('ajax.php',{action:'list_internal_links'},function(res){
    if(res.success){ internalLinksTable.clear(); internalLinksTable.rows.add(res.data).draw(); }
  },'json');
}
function initExternalLinks(){
  externalLinksTable=$('#externalLinksTable').DataTable({
    columns:[
      {data:'url'},
      {data:'title'},
      {data:null,orderable:false,render:row=>`<button class='btn btn-sm btn-warning el-edit' data-id='${row.id}'>ویرایش</button>`}
    ],
    searching:false,paging:false,info:false,columnDefs:[{targets:'_all',className:'text-center'}]
  });
  loadExternalLinks();
}
function loadExternalLinks(){
  $.post('ajax.php',{action:'list_external_links'},function(res){
    if(res.success){ externalLinksTable.clear(); externalLinksTable.rows.add(res.data).draw(); }
  },'json');
}
$('#productsTable').on('click','.history',function(){
  const pid=$(this).data('id');
  $('#historyModal').data('pid',pid).modal('show');
  loadHistory(pid);
});
$('#historyTable').on('click','.revert',function(){
  const version=$(this).data('version');
  const pid=$('#historyModal').data('pid');
  Swal.fire({title:'بازگردانی؟',icon:'warning',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=revert_content&product_id=${pid}&version=${version}`})
        .then(r=>r.json()).then(r=>{
          if(r.success){ toastr.success('بازگردانی انجام شد'); loadHistory(pid); loadProducts(); }
          else { toastr.error(r.message); }
        });
    }
  });
});

$('#internalLinksModal').on('shown.bs.modal',function(){ if(!internalLinksTable) initInternalLinks();});
$('#externalLinksModal').on('shown.bs.modal',function(){ if(!externalLinksTable) initExternalLinks();});

$('#saveInternalLink').click(function(){
  $.post('ajax.php',{action:'save_internal_link',id:$('#il_id').val(),category:$('#il_category').val(),url:$('#il_url').val(),title:$('#il_title').val()},function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#il_id').val('');$('#il_category').val('');$('#il_url').val('');$('#il_title').val(''); loadInternalLinks(); }
    else toastr.error('خطا');
  },'json');
});
$('#internalLinksTable').on('click','.il-edit',function(){
  const d=internalLinksTable.row($(this).closest('tr')).data();
  $('#il_id').val(d.id); $('#il_category').val(d.category); $('#il_url').val(d.url); $('#il_title').val(d.title);
});

$('#syncInternalLinks').click(function(){
  $.post('ajax.php',{action:'sync_internal_links'},function(res){
    if(res.success){ toastr.success('همگام‌سازی انجام شد'); loadInternalLinks(); }
    else toastr.error('خطا');
  },'json');
});

$('#saveExternalLink').click(function(){
  $.post('ajax.php',{action:'save_external_link',id:$('#el_id').val(),url:$('#el_url').val(),title:$('#el_title').val()},function(res){
    if(res.success){ toastr.success('ذخیره شد'); $('#el_id').val('');$('#el_url').val('');$('#el_title').val(''); loadExternalLinks(); }
    else toastr.error('خطا');
  },'json');
});
$('#externalLinksTable').on('click','.el-edit',function(){
  const d=externalLinksTable.row($(this).closest('tr')).data();
  $('#el_id').val(d.id); $('#el_url').val(d.url); $('#el_title').val(d.title);
});
function initUsers(){
  usersTable=$('#usersTable').DataTable({
    columns:[
      {title:'ID'},
      {title:'نام کاربری'},
      {title:'نقش'},
      {title:'وضعیت'},
      {title:'ایجاد'},
      {title:'اقدامات'}
    ],
    pageLength:20,
    ordering:true,
    searching:true,
    info:false,
    language:{search:'',searchPlaceholder:'جستجو...'},
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  loadUsers();
}
function loadUsers(){
 const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
 NProgress.start();
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=users_list'})
  .then(r=>r.json()).then(r=>{
    if(r.success){
      const rows=r.data.map(u=>[
        u.id,
        u.username,
        u.role,
        u.status,
        toJalali(u.created_at),
        `<button class='btn btn-sm btn-secondary assign-products' data-id='${u.id}' data-name='${u.username}'>تخصیص</button> `+
        `<button class='btn btn-sm btn-info user-log' data-id='${u.id}' data-name='${u.username}'>لاگ</button> `+
        `<button class='btn btn-sm btn-primary edit-user' data-id='${u.id}'>ویرایش</button> `+
        `<button class='btn btn-sm btn-danger delete-user' data-id='${u.id}'>حذف</button>`
      ]);
      usersTable.clear();
      usersTable.rows.add(rows).draw();
    }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});
}

function initAssignments(){
  assignUsersTable=$('#assignUsersTable').DataTable({
    columns:[
      {title:'کاربر'},
      {title:'حالت فعال'},
      {title:'تعداد'},
      {title:'اقدامات'}
    ],
    pageLength:20,
    searching:false,
    ordering:false,
    info:false,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  loadAssignUsers();
}

function loadAssignUsers(){
 const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
 NProgress.start();
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=assignment_users'})
  .then(r=>r.json()).then(r=>{
    if(r.success && assignUsersTable){
      const rows=r.data.map(u=>[
        u.username,
        u.mode||'',
        u.cnt,
        `<button class='btn btn-sm btn-secondary manage-assign' data-id='${u.id}' data-name='${u.username}'>مدیریت محصولات</button>`
      ]);
      assignUsersTable.clear();
      assignUsersTable.rows.add(rows).draw();
    }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});
}

function setModeUI(mode){
  $('#assignTabs button').each(function(){
    const target=$(this).attr('data-bs-target');
    const m=(target==='#assignQuota')?'quota':(target==='#assignCategory')?'category':'manual';
    if(mode===m){ $(this).removeClass('disabled'); }
    else{ $(this).addClass('disabled'); }
  });
  if(mode){
    const target=mode==='quota'?'#assignQuota':mode==='category'?'#assignCategory':'#assignManual';
    const el=document.querySelector(`#assignTabs button[data-bs-target="${target}"]`);
    if(el) new bootstrap.Tab(el).show();
  }
}

function loadAssignedProducts(uid){
  if(assignedProductsTable){ assignedProductsTable.clear().destroy(); }
  assignedProductsTable=$('#assignedProductsTable').DataTable({
    columns:[
      {title:'ID'},
      {title:'نام'},
      {title:'حذف'},
      {title:'انتقال'}
    ],
    pageLength:10,
    searching:false,
    ordering:false,
    info:false,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
  NProgress.start();
  fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=user_assignments&user_id=${uid}`})
   .then(r=>r.json()).then(r=>{
     if(r.success && assignedProductsTable){
       const rows=r.data.map(p=>[
         p.id,
         p.title,
         `<button class='btn btn-sm btn-danger rm-assign' data-id='${p.id}'>حذف</button>`,
         `<button class='btn btn-sm btn-warning transfer-assign' data-id='${p.id}'>انتقال</button>`
       ]);
       assignedProductsTable.clear();
       assignedProductsTable.rows.add(rows).draw();
     }
   }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});
}

function openAssignModal(id,name){
  $('#assignUserId').val(id);
  $('#assignUser').text(name);
  loadAssignData();
  loadAssignedProducts(id);
  $.post('ajax.php',{action:'get_assign_mode',user_id:id},function(r){
    $('input[name="assignMode"]').prop('checked',false);
    $('#confirmMode').prop('checked',false);
    if(r.success && r.data && r.data.mode){
      $('input[name="assignMode"][value="'+r.data.mode+'"]').prop('checked',true);
      setModeUI(r.data.mode);
    }else{ setModeUI(null); }
  },'json');
  $('#assignModal').modal('show');
}
function loadAssignData(){
 const catSel=$('#assignCategorySelect');
 if(catSel.hasClass('select2-hidden-accessible')) catSel.select2('destroy');
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=list_categories'})
  .then(r=>r.json()).then(r=>{
    if(r.success){
      catSel.empty();
      r.data.forEach(c=> catSel.append(`<option value='${c.id}'>${c.name}</option>`));
      catSel.select2({dropdownParent:$('#assignModal'),width:'100%'});
    }
  });
const manSel=$('#assignManualSelect');
if(manSel.hasClass('select2-hidden-accessible')) manSel.select2('destroy');
manSel.select2({
  dropdownParent:$('#assignModal'),
  ajax:{
    url:'ajax.php',
    type:'POST',
    dataType:'json',
    delay:250,
    data:params=>({action:'unassigned_products',q:params.term||''}),
    processResults:data=>({results:data.data})
  },
  width:'100%'
});
manSel.val(null).trigger('change');
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=product_total'})
  .then(r=>r.json()).then(r=>{ if(r.success) $('#totalProducts').text(r.total); });
}

function loadRoleOptions(selected){
 $.post('ajax.php',{action:'roles_list'},function(r){
   if(r.success){
     const sel=$('#user_role');
     sel.empty();
     r.data.forEach(ro=> sel.append(`<option value="${ro.id}">${ro.name}</option>`));
     if(selected) sel.val(selected);
   }
 },'json');
}

function initRoles(){
  if(!rolesTable){
    rolesTable=$('#rolesTable').DataTable({
      columns:[
        {title:'نام نقش'},
        {title:'دسترسی‌ها'},
        {title:'اقدامات'}
      ],
      pageLength:10,
      searching:false,
      ordering:false,
      info:false,
      columnDefs:[{targets:'_all',className:'text-center'}]
    });
  }
}

function loadRoles(){
 $.post('ajax.php',{action:'roles_list'},function(r){
   if(r.success && rolesTable){
     const rows=r.data.map(ro=>[
       ro.name,
       ro.permissions==='all'?'همه':ro.permissions,
       ro.id==1?'':`<button class='btn btn-sm btn-primary edit-role' data-id='${ro.id}'>ویرایش</button> <button class='btn btn-sm btn-danger del-role' data-id='${ro.id}'>حذف</button>`
     ]);
     rolesTable.clear();
     rolesTable.rows.add(rows).draw();
   }
 },'json');
}
function initLogs(){
  logsTable=$('#logsTable').DataTable({
    columns:[
      {title:'کاربر'},
      {title:'عملیات'},
      {title:'آی‌پی'},
      {title:'کشور'},
      {title:'شهر'},
      {title:'ISP'},
      {title:'زمان'}
    ],
    pageLength:20,
    searching:false,
    ordering:true,
    info:false,
    columnDefs:[{targets:'_all',className:'text-start'}],
    language:{paginate:{previous:'قبلی',next:'بعدی'}}
  });
  sessionsTable=$('#sessionsTable').DataTable({
    columns:[
      {title:'کاربر'},
      {title:'آی‌پی'},
      {title:'دستگاه'},
      {title:'انقضا'},
      {title:''}
    ],
    pageLength:20,
    searching:false,
    ordering:false,
    info:false,
    columnDefs:[{targets:'_all',className:'text-start'}],
    language:{paginate:{previous:'قبلی',next:'بعدی'}}
  });
  loadLogs();
}
function loadLogs(){
 const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
 NProgress.start();
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logs_list'})
  .then(r=>r.json()).then(r=>{
    if(r.success && logsTable){
      const rows=r.data.map(l=>[
        l.username,l.action,l.ip_address,l.country,l.city,l.isp,toJalali(l.timestamp)
      ]);
      logsTable.clear();
      logsTable.rows.add(rows).draw();
      if(r.message) log('Logs: '+r.message);
    }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});

 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=sessions_list'})
  .then(r=>r.json()).then(r=>{
    if(r.success && sessionsTable){
      const rows=r.data.map(s=>[
        s.username,s.ip_address,s.device_info,toJalali(s.expires_at),
        `<button class='btn btn-sm btn-danger logout-session' data-id='${s.id}'>خروج</button>`
      ]);
      sessionsTable.clear();
      sessionsTable.rows.add(rows).draw();
    }
  });
}
function initSearchConsole(){
  log('SearchConsole: init');
  const today=new Date().toISOString().slice(0,10);
  const past=new Date(Date.now()-89*24*3600*1000).toISOString().slice(0,10);
  $('#kwTo').val(today); $('#kwFrom').val(past);
  searchConsoleTable=$('#searchConsoleTable').DataTable({
    columns:[
      {title:'کوئری'},
      {title:'کلیک'},
      {title:'ایمپرشن'},
      {title:'CTR'},
      {title:'رتبه'}
    ],
    paging:true,
    pageLength:10,
    lengthMenu:[[10,25,50,100],[10,25,50,100]],
    searching:false,
    ordering:true,
    info:true,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  loadSearchConsole();
}
function loadSearchConsole(){
  const from=document.getElementById('kwFrom').value;
  const to=document.getElementById('kwTo').value;
  const q=document.getElementById('kwQuery').value;
  const device=document.getElementById('kwDevice').value;
  const country=document.getElementById('kwCountry').value;
  const dim=document.getElementById('kwDimension').value;
  log(`SearchConsole: sending request from ${from} to ${to} q=${q}`);
  const params=new URLSearchParams({action:'fetch_search_console',from:from,to:to,query:q,device:device,country:country,dimension:dim});
  const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
  NProgress.start();
  fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
    .then(r=>{ log('SearchConsole: HTTP '+r.status); return r.json(); })
    .then(r=>{
      log('SearchConsole: response '+JSON.stringify(r));
      if(r.success && searchConsoleTable){
        log('SearchConsole: updating table');
        const rows=r.data.rows.map(d=>[
          d.key,d.clicks,d.impressions, d.ctr+'%', d.position
        ]);
        const headerMap={query:'کوئری',page:'صفحه',country:'کشور',device:'دستگاه',searchAppearance:'نوع نمایش'};
        $('#searchConsoleTable thead th').eq(0).text(headerMap[dim]||'کوئری');
        searchConsoleTable.clear();
        searchConsoleTable.rows.add(rows).draw();
        const labels=r.data.dates.map(d=>toJalaliDate(d.date));
        const clicks=r.data.dates.map(d=>d.clicks);
        const impressions=r.data.dates.map(d=>d.impressions);
        document.getElementById('scClicks').textContent=r.data.summary.clicks;
        document.getElementById('scImpressions').textContent=r.data.summary.impressions;
        document.getElementById('scCtr').textContent=r.data.summary.ctr+'%';
        document.getElementById('scPosition').textContent=r.data.summary.position;
        if(scChart){ scChart.destroy(); }
        const ctx=document.getElementById('scChart');
        scChart=new Chart(ctx,{type:'line',data:{labels:labels,datasets:[{label:'کلیک',data:clicks,borderColor:'#0d6efd',backgroundColor:'rgba(13,110,253,0.1)',tension:0.3},{label:'ایمپرشن',data:impressions,borderColor:'#198754',backgroundColor:'rgba(25,135,84,0.1)',tension:0.3}]} });
      } else {
        log('SearchConsole: failed to load data');
        if(r.message) log('SearchConsole: '+r.message);
        if(window.toastr){ toastr.warning(r.message || 'داده‌ای یافت نشد'); }
      }
    })
    .catch(err=>log('SearchConsole: error '+err))
    .finally(()=>{toastr.clear(toast); NProgress.done();});
}

function initUserLog(){
  userLogDataTable=$('#userLogTable').DataTable({
    columns:[
      {title:'زمان'},
      {title:'عملیات'},
      {title:'آی‌پی'},
      {title:'کشور'},
      {title:'شهر'},
      {title:'ISP'}
    ],
    pageLength:20,
    searching:false,
    ordering:false,
    info:false,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
  userSessionDataTable=$('#userSessionTable').DataTable({
    columns:[
      {title:'آی‌پی'},
      {title:'دستگاه'},
      {title:'انقضا'},
      {title:''}
    ],
    pageLength:20,
    searching:false,
    ordering:false,
    info:false,
    columnDefs:[{targets:'_all',className:'text-center'}]
  });
}
function loadUserLogs(id){
 if(!userLogDataTable || !userSessionDataTable) initUserLog();
 const toast=toastr.info('لطفاً صبر کنید، داده‌ها در حال بارگیری است',{timeOut:0,extendedTimeOut:0});
 NProgress.start();
 fetch('ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=fetch_user_logs&id='+id})
  .then(r=>r.json()).then(r=>{
    if(r.success){
      const rows=r.logs.map(d=>[toJalali(d.ts),d.action,d.ip,d.country,d.city,d.isp]);
      userLogDataTable.clear();
      userLogDataTable.rows.add(rows).draw();
      const srows=r.sessions.map(s=>[
        s.ip_address,s.device_info,toJalali(s.expires_at),
        `<button class='btn btn-sm btn-danger logout-session' data-id='${s.id}'>خروج</button>`
      ]);
      userSessionDataTable.clear();
      userSessionDataTable.rows.add(srows).draw();
      $('#logModal').modal('show');
      if(r.message) log('UserLogs: '+r.message);
    }else{ toastr.error(r.message); }
  }).catch(()=>{}).finally(()=>{toastr.clear(toast);NProgress.done();});
}
$('button[data-bs-toggle="tab"]').on('shown.bs.tab',function(e){
  const t=$(e.target).data('bs-target');
  if(t==='#products'){
    if(!productsInit){initProducts(); productsInit=true;} else loadProducts();
  }else if(t==='#users'){
    if(!usersInit){initUsers(); usersInit=true;} else loadUsers();
  }else if(t==='#assignments'){
    if(!assignmentsInit){initAssignments(); assignmentsInit=true;} else loadAssignUsers();
  }else if(t==='#searchConsole'){
    const sub=$('#scNav button.active').data('bs-target');
    if(!scKeywordsInit){initSearchConsole(); scKeywordsInit=true;}
    loadSearchConsole();
  }
});
$('#scNav button[data-bs-toggle="tab"]').on('shown.bs.tab',function(e){
  const t=$(e.target).data('bs-target');
  if(t==='#scKeywords'){
    if(!scKeywordsInit){initSearchConsole(); scKeywordsInit=true;}
    loadSearchConsole();
  }
});
$('#filterKeywords').on('click',function(){loadSearchConsole();});
$('button.nav-link.active[data-bs-toggle="tab"]').each(function(){ $(this).trigger('shown.bs.tab'); });


$(document).on('click','.edit',function(){
 var id=$(this).data('id');
 $.post('ajax.php',{action:'get_product',id:id},function(res){
  if(res.success){
    $('#prod_id').val(res.product.id);
    $('#modalProdName').text(res.product.name);
    $('#prod_name').val(res.product.name);
    $('#prod_slug').val(res.product.slug).data('old',res.product.slug).prop('disabled',true);
    if(descEditor){ descEditor.setData(res.product.description); }
    $('#prod_desc_html').val(res.product.description);
    $('#prod_price').val(res.product.price ? res.product.price.replace(/\B(?=(\d{3})+(?!\d))/g,',') : '');
    $('#stock_status').val(res.stock_status);
    $('#cat_list').html(res.categories_html);
    $('#seo_title').val(res.seo_title);
    $('#seo_desc').val(res.seo_desc);
    $('#seo_focus').val(res.focus_keyword);
    $('#seo_suggestions').addClass('d-none');
    $('#seo_score').removeClass().addClass('badge bg-secondary').text('0');
    $('#seo_feedback').empty();
    $('#seo_prompt').val(res.seo_prompt);
    $('#viewProduct').attr('href', res.product_url);
    var modal=new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
    loadServerSeo(res.product.id);
  }else{
    Swal.fire('خطا',res.message,'error');
   }
 },'json');
});


$('#apply_title').click(function(){
  $('#seo_title').val($('#seo_sug_title').text());
});
$('#apply_meta').click(function(){
  $('#seo_desc').val($('#seo_sug_meta').text());
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
      if(descEditor && $('#descTabs .nav-link.active').attr('data-bs-target') === '#desc-html'){
        descEditor.setData($('#prod_desc_html').val());
      }
      $.post('ajax.php',{
        action:'save_product',
        id:$('#prod_id').val(),
        name:$('#prod_name').val(),
        slug:$('#prod_slug').val(),
        old_slug:$('#prod_slug').data('old'),
        description: descEditor ? descEditor.getData() : $('#prod_desc_html').val(),
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
          if(res.indexed){ toastr.success('درخواست ایندکس به گوگل ارسال شد'); }
          else if(res.index_log){ toastr.warning('ایندکس: '+res.index_log); }
          if($('#prod_slug').data('old')!==$('#prod_slug').val()){
            if(res.redirect){
              toastr.success('ریدایرکت 301 با موفقیت ثبت شد');
            }else{
              toastr.error('ثبت ریدایرکت با خطا مواجه شد');
            }
            $('#prod_slug').data('old',$('#prod_slug').val());
          }
          loadProducts();
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
        if(r.success){
          toastr.success('کلمات کلیدی به‌روزرسانی شد');
          if(r.report){
            r.report.ok.forEach(n=>log('Bulk keyword ok: '+n));
            r.report.fail.forEach(n=>log('Bulk keyword fail: '+n));
          }
        }else{toastr.error(r.message);}
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
        if(r.success){
          toastr.success('توضیحات متا تولید شد');
          if(r.report){
            r.report.ok.forEach(n=>log('Bulk meta ok: '+n));
            r.report.fail.forEach(n=>log('Bulk meta fail: '+n));
          }
        }else{toastr.error(r.message);}
      },'json');
    }
  });
});

function toJalali(d){
  const date=new Date(d.replace(' ','T'));
  return date.toLocaleString('fa-IR-u-ca-persian',{dateStyle:'short',timeStyle:'short'});
}
function toJalaliDate(d){
  return new Date(d).toLocaleDateString('fa-IR-u-ca-persian',{dateStyle:'short'});
}
function showUserLogs(id, name){
  $('#logUser').text(name).data('id',id);
  loadUserLogs(id);
}

$('#usersTable').on('click','.assign-products',function(){
  const id=$(this).data('id');
  const name=$(this).data('name');
  openAssignModal(id,name);
});
$('#assignUsersTable').on('click','.manage-assign',function(){
  const id=$(this).data('id');
  const name=$(this).data('name');
  openAssignModal(id,name);
});

$('#usersTable').on('click','.user-log',function(){
  const id=$(this).data('id');
  const name=$(this).data('name');
  showUserLogs(id,name);
});

$('#btnAssignQuota').click(function(){
  const uid=$('#assignUserId').val();
  const cnt=$('#assignQuotaCount').val();
  if(!cnt){ toastr.error('تعداد را وارد کنید'); return; }
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      $.post('ajax.php',{action:'assign_quota',user_id:uid,count:cnt},function(r){
        if(r.success){ toastr.success('تخصیص انجام شد'); loadAssignData(); }
        else{ toastr.error(r.message); }
      },'json');
    }
  });
});

$('#btnAssignCategory').click(function(){
  const uid=$('#assignUserId').val();
  const cat=$('#assignCategorySelect').val();
  if(!cat){ toastr.error('دسته‌بندی را انتخاب کنید'); return; }
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      $.post('ajax.php',{action:'assign_category',user_id:uid,cat_id:cat},function(r){
        if(r.success){ toastr.success('تخصیص انجام شد'); loadAssignData(); }
        else{ toastr.error(r.message); }
      },'json');
    }
  });
});

$('#btnAssignManual').click(function(){
  const uid=$('#assignUserId').val();
  const ids=$('#assignManualSelect').val();
  if(!ids || ids.length==0){ toastr.error('محصولات را انتخاب کنید'); return; }
  Swal.fire({title:'آیا مطمئن هستید؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
    if(res.isConfirmed){
      $.post('ajax.php',{action:'assign_manual',user_id:uid,ids:ids.join(',')},function(r){
        if(r.success){ toastr.success('تخصیص انجام شد'); loadAssignData(); }
        else{ toastr.error(r.message); }
      },'json');
    }
  });
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
  $.post('ajax.php',{action:'logout'},function(r){
    if(r.success){ location.reload(); }
    else{ toastr.error('خروج انجام نشد'); }
  },'json');
});

$('#openLogsCard').click(function(){
  if(!logsInit){initLogs(); logsInit=true;} else loadLogs();
  $('#logsModal').modal('show');
});

$(document).on('click','.logout-session',function(){
  const id=$(this).data('id');
  $.post('ajax.php',{action:'session_logout',id:id},function(r){
    if(r.success){
      toastr.success('نشست خاتمه یافت');
      loadLogs();
      if($('#logModal').hasClass('show')){
        const uid=$('#logUser').data('id')||currentUserId;
        loadUserLogs(uid);
      }
    }else{ toastr.error(r.message); }
  },'json');
});

$('#myAccount').click(function(e){
  e.preventDefault();
  $.post('ajax.php',{action:'user_get',id:currentUserId},function(r){
    if(r.success){
      $('#user_id').val(r.data.id);
      $('#user_username').val(r.data.username);
      $('#user_fullname').val(r.data.full_name);
      $('#user_phone').val(r.data.phone_number);
      loadRoleOptions(r.data.role_id);
      $('#user_status').val(r.data.status);
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
  const table=$('#'+id);
  table.find('tbody').html(rows);
  if ($.fn.DataTable.isDataTable(table)) {
    table.DataTable().clear().destroy();
  }
  table.DataTable({searching:true,paging:false,info:false});
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



$('#addUserBtn').click(function(){
 $('#userForm')[0].reset();
 $('#user_id').val('');
 loadRoleOptions();
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
   loadRoleOptions(r.data.role_id);
   $('#user_status').val(r.data.status);
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
      if(r.success){ toastr.success('حذف شد'); loadUsers(); }
      else { toastr.error(r.message); }
     },'json');
  }
 });
});

$('#userForm').submit(function(e){
 e.preventDefault();
 const data={
  action: $('#user_id').val() ? 'user_update' : 'user_create',
  id: $('#user_id').val(),
  username: $('#user_username').val(),
  password: $('#user_password').val(),
  full_name: $('#user_fullname').val(),
  phone_number: $('#user_phone').val(),
  role_id: $('#user_role').val(),
  status: $('#user_status').val()
 };
 $.post('ajax.php',data,function(r){
    if(r.success){
     toastr.success('ذخیره شد');
     $('#userModal').modal('hide');
     loadUsers();
    } else { toastr.error(r.message); }
 },'json');
});

$('#roleModal').on('shown.bs.modal',function(){
  $('#roleForm')[0].reset();
  $('#role_id').val('');
  $('.rperm').prop('checked',false);
  initRoles();
  loadRoles();
});

$('#roleForm').submit(function(e){
 e.preventDefault();
 const perms=$('.rperm:checked').map((i,el)=>el.value).get().join(',');
 $.post('ajax.php',{action:'role_save',id:$('#role_id').val(),name:$('#role_name').val(),permissions:perms},function(r){
   if(r.success){
     toastr.success('ذخیره شد');
     $('#roleForm')[0].reset();
     $('#role_id').val('');
     $('.rperm').prop('checked',false);
     loadRoles();
     loadRoleOptions();
   } else { toastr.error(r.message); }
 },'json');
});

$('#rolesTable').on('click','.edit-role',function(){
 const id=$(this).data('id');
 $.post('ajax.php',{action:'role_get',id:id},function(r){
  if(r.success){
    $('#role_id').val(r.data.id);
    $('#role_name').val(r.data.name);
    $('.rperm').prop('checked',false);
    if(r.data.permissions){ r.data.permissions.split(',').forEach(p=>$('.rperm[value="'+p+'"]').prop('checked',true)); }
  }
 },'json');
});

$('#rolesTable').on('click','.del-role',function(){
 const id=$(this).data('id');
 Swal.fire({title:'حذف نقش؟',icon:'warning',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'خیر'}).then(res=>{
  if(res.isConfirmed){
    $.post('ajax.php',{action:'role_delete',id:id},function(r){
      if(r.success){ toastr.success('حذف شد'); loadRoles(); loadRoleOptions(); }
      else { toastr.error(r.message); }
    },'json');
  }
 });
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
