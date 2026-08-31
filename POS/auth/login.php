<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? '../php/dashboard.php' : '../php/menu.php'));
    exit;
}

$error = '';
if (!empty($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$info = match($_GET['reason'] ?? '') {
    'logout'          => 'You have been signed out.',
    'unauthenticated' => 'Please sign in to continue.',
    default           => '',
};

$saved_username = htmlspecialchars($_POST['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="tl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kofee Café — Sign In</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --espresso:#241A2E;
    --espresso-deep:#181120;
    --cream:#FBF3E9;
    --caramel:#C97B3D;
    --caramel-light:#E6A25C;
    --latte:#EFE0CC;
  }
  *{font-family:'Inter',sans-serif;}
  .font-display{font-family:'Playfair Display',serif;}

  body{
    background: radial-gradient(circle at 30% 20%, #f5ede0 0%, #eee3d1 60%, #e7dac4 100%);
  }

  .bean-field{
    position:absolute; inset:0;
    background-image: radial-gradient(circle at 20% 30%, rgba(201,123,61,0.10) 0, transparent 3%),
                       radial-gradient(circle at 70% 60%, rgba(201,123,61,0.10) 0, transparent 3%),
                       radial-gradient(circle at 45% 85%, rgba(201,123,61,0.07) 0, transparent 3%),
                       radial-gradient(circle at 85% 15%, rgba(201,123,61,0.07) 0, transparent 3%),
                       radial-gradient(circle at 10% 75%, rgba(201,123,61,0.06) 0, transparent 3%);
    pointer-events:none;
  }

  .glow-orb{
    position:absolute;
    width:380px; height:380px;
    background: radial-gradient(circle, rgba(230,162,92,0.28) 0%, transparent 70%);
    filter: blur(10px);
    animation: drift 10s ease-in-out infinite alternate;
    pointer-events:none;
  }
  @keyframes drift{
    0%{ transform: translate(0,0) scale(1); }
    100%{ transform: translate(24px,-18px) scale(1.08); }
  }

  .steam{
    position:absolute;
    bottom:100%;
    width:3px;
    border-radius:999px;
    background: linear-gradient(to top, rgba(251,243,233,0.6), rgba(251,243,233,0));
    animation: rise 3.2s ease-in infinite;
    opacity:0;
  }
  @keyframes rise{
    0%{ transform: translateY(0) translateX(0) scaleY(0.4); opacity:0; }
    15%{ opacity:0.7; }
    50%{ transform: translateY(-20px) translateX(4px) scaleY(1); opacity:0.5;}
    100%{ transform: translateY(-42px) translateX(-4px) scaleY(1.3); opacity:0; }
  }

  .cup-float{ animation: float 4.5s ease-in-out infinite; }
  @keyframes float{
    0%,100%{ transform: translateY(0px); }
    50%{ transform: translateY(-6px); }
  }

  .field input{ transition: all .25s ease; }
  .field input:focus{
    box-shadow: 0 0 0 3px rgba(201,123,61,0.22);
    border-color: var(--caramel);
  }
  .field label{ transition: color .25s ease; }
  .field:focus-within label{ color: var(--caramel); }

  .btn-brew{
    background: linear-gradient(135deg, var(--caramel) 0%, var(--espresso-deep) 140%);
    transition: transform .2s ease, box-shadow .2s ease, background-position .4s ease;
    background-size: 160% 160%;
    background-position: 0% 0%;
  }
  .btn-brew:hover{
    transform: translateY(-2px);
    box-shadow: 0 12px 26px -8px rgba(24,17,32,0.55);
    background-position: 100% 100%;
  }
  .btn-brew:active{ transform: translateY(0px) scale(0.98); }

  .card-pop{ animation: pop .6s cubic-bezier(.2,.8,.2,1) both; }
  @keyframes pop{
    0%{ opacity:0; transform: translateY(18px) scale(.97); }
    100%{ opacity:1; transform: translateY(0) scale(1); }
  }

  .form-pop{ animation: formPop .5s cubic-bezier(.2,.8,.2,1) .2s both; }
  @keyframes formPop{
    0%{ opacity:0; transform: translateY(10px) scale(.98); }
    100%{ opacity:1; transform: translateY(0) scale(1); }
  }

  .brand-in{ animation: fadeUp .8s ease .35s both; }
  @keyframes fadeUp{
    0%{ opacity:0; transform: translateY(14px); }
    100%{ opacity:1; transform: translateY(0); }
  }

  ::selection{ background: var(--caramel-light); color: var(--espresso-deep); }
</style>

</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-8">

<!-- Single dark card, everything inside it -->
<div class="relative w-full max-w-md sm:max-w-2xl rounded-[28px] overflow-hidden card-pop"
     style="background: linear-gradient(150deg, var(--espresso) 0%, var(--espresso-deep) 100%); box-shadow:0 30px 60px -18px rgba(24,17,32,0.45);">

  <div class="bean-field"></div>
  <div class="glow-orb -top-20 -right-16"></div>
  <div class="glow-orb bottom-0 -left-20" style="animation-delay:2s;"></div>

  <div class="relative flex flex-col sm:flex-row items-center gap-8 px-6 py-8 sm:px-10 sm:py-10">

    <!-- Floating white login form, inset within the dark card -->
    <div class="form-pop bg-[color:var(--cream)] rounded-2xl shadow-2xl w-full sm:w-[280px] px-6 py-7 shrink-0"
         style="box-shadow:0 18px 40px -12px rgba(0,0,0,0.35);">
      <h1 class="font-display text-xl mb-5" style="color:var(--espresso)">Sign In</h1>

      <?php if ($error): ?>
      <div class="mb-4 rounded-lg border-2 border-red-300 bg-red-50 px-3 py-2 text-[12px] text-red-600 font-medium">
        <?= htmlspecialchars($error) ?>
      </div>
      <?php elseif ($info): ?>
      <div class="mb-4 rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-[12px]" style="color:var(--espresso)">
        <?= htmlspecialchars($info) ?>
      </div>
      <?php endif; ?>

      <form class="space-y-4" method="POST" action="login_process.php">
        <div class="field">
          <label class="block text-[11px] font-semibold uppercase tracking-wide mb-1" style="color:var(--espresso)">Username</label>
          <input type="text" name="username" placeholder="Username" value="<?= $saved_username ?>" required
            class="w-full rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-sm outline-none placeholder:text-stone-400"
            style="color:var(--espresso)">
        </div>

        <div class="field">
          <label class="block text-[11px] font-semibold uppercase tracking-wide mb-1" style="color:var(--espresso)">Password</label>
          <input type="password" name="password" placeholder="••••••••" required
            class="w-full rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-sm outline-none placeholder:text-stone-400"
            style="color:var(--espresso)">
        </div>
        

        <button type="submit" class="btn-brew w-full text-white text-sm font-semibold rounded-lg py-2.5 mt-1 shadow-lg">
          Sign In
        </button>

        <p class="text-center text-[11px] text-stone-500 pt-1">
          Trouble signing in? <a href="#" class="font-semibold hover:underline" style="color:var(--caramel)">Contact your manager</a>
        </p>
      </form>
    </div>
    <div class="brand-in relative flex-1 flex flex-col items-center text-center py-2">
      <div class="relative mx-auto mb-4 cup-float" style="width:96px;">
        <span class="steam" style="left:36%; animation-delay:0s;"></span>
        <span class="steam" style="left:50%; animation-delay:1.1s;"></span>
        <span class="steam" style="left:63%; animation-delay:2.1s;"></span>
        <svg viewBox="0 0 120 100" width="96" height="80">
          <ellipse cx="45" cy="88" rx="42" ry="6" fill="rgba(0,0,0,0.25)"/>
          <path d="M12 30 H78 V60 C78 78 63 88 45 88 C27 88 12 78 12 60 Z" fill="var(--cream)"/>
          <path d="M78 38 C96 38 96 66 78 66" fill="none" stroke="var(--cream)" stroke-width="6" stroke-linecap="round"/>
          <ellipse cx="45" cy="30" rx="33" ry="7" fill="var(--caramel-light)"/>
          <ellipse cx="45" cy="30" rx="33" ry="7" fill="none" stroke="var(--espresso)" stroke-width="1" opacity="0.15"/>
        </svg>
      </div>

      <h2 class="font-display text-2xl sm:text-3xl text-[color:var(--cream)] mb-1">Kofee Café</h2>
      <p class="text-xs sm:text-sm tracking-wide" style="color:var(--latte); opacity:0.75;">
        Sign in into your account.
    </p>

      <div class="mt-5 flex items-center justify-center gap-2">
        <span class="w-1.5 h-1.5 rounded-full" style="background:var(--caramel-light)"></span>
        <span class="w-6 h-1.5 rounded-full" style="background:var(--caramel)"></span>
        <span class="w-1.5 h-1.5 rounded-full" style="background:var(--caramel-light)"></span>
      </div>
    </div>

  </div>
</div>

<script src="../js/login.js"></script>
</body>
</html>