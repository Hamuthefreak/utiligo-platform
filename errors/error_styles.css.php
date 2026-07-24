<?php header('Content-Type: text/css'); ?>
*,::before,::after{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:#080D18;
  color:#CBD5E1;
  display:flex;align-items:center;justify-content:center;
  min-height:100vh;
  padding:24px;
}
.wrap{
  text-align:center;
  max-width:460px;
  width:100%;
  animation:fadeUp .5s cubic-bezier(.4,0,.2,1) both;
}
.code{
  font-size:clamp(5rem,20vw,9rem);
  font-weight:900;
  line-height:1;
  background:linear-gradient(135deg,#10B981 0%,#34D399 50%,#6EE7B7 100%);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  background-clip:text;
  margin-bottom:16px;
  letter-spacing:-4px;
}
h1{
  font-size:1.6rem;
  font-weight:800;
  color:#F1F5F9;
  margin-bottom:12px;
}
p{
  font-size:.95rem;
  color:#64748B;
  line-height:1.6;
  margin-bottom:32px;
}
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  background:#10B981;
  color:#0A0F1E;
  font-weight:700;
  font-size:.875rem;
  padding:12px 28px;
  border-radius:999px;
  text-decoration:none;
  border:none;
  cursor:pointer;
  margin:0 6px;
  transition:background .2s,transform .15s,box-shadow .2s;
  box-shadow:0 4px 20px rgba(16,185,129,.3);
}
.btn:hover{background:#0ea271;transform:translateY(-1px);box-shadow:0 8px 28px rgba(16,185,129,.4);}
.btn.ghost{
  background:transparent;
  color:#64748B;
  border:1px solid rgba(255,255,255,.1);
  box-shadow:none;
}
.btn.ghost:hover{color:#CBD5E1;border-color:rgba(255,255,255,.2);}
@keyframes fadeUp{
  from{opacity:0;transform:translateY(24px)}
  to  {opacity:1;transform:translateY(0)}
}
