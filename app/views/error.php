<?php
// Variables available here: $errorId (string), $userMessage (string)
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Something went wrong</title>
  <style>
    /* minimal inline styling—swap for your CSS */
    body { font-family: sans-serif; background:#f5f5f5; color:#333; text-align:center; padding:4em; }
    .card { background:white; padding:2em; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1); display:inline-block; max-width:500px; }
    h1 { margin-top:0; color:#c0392b; }
    a { color:#2980b9; text-decoration:none; }
    a:hover { text-decoration:underline; }
    .error-id { font-family: monospace; background:#eee; padding:0.2em 0.4em; border-radius:4px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Oops! Something went wrong.</h1>
    <p><?= htmlspecialchars($userMessage) ?></p>
    <p>Error ID: <span class="error-id"><?= htmlspecialchars($errorId) ?></span></p>
    <p>
      <a href="/">Return Home</a> &nbsp;|&nbsp;
      <a href="mailto:support@example.com">Contact Support</a>
    </p>
  </div>
</body>
</html>
