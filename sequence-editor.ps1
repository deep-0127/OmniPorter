$content = Get-Content $args[0]; $content = $content -replace '^pick 1053203', 'edit 1053203'; $content | Set-Content $args[0]
