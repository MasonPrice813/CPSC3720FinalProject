RewriteEngine On

# Pass Authorization and custom headers through
CGIPassAuth On

# If file or directory exists, serve it normally
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Otherwise send request to index.php
RewriteRule ^ index.php [QSA,L]
