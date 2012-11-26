<ul>
<?php foreach( $files as $name => $file ): ?>
	<li><a href="<?php echo $file->link; ?>"><?php echo $name; ?></a></li>
<?php endforeach; ?>
</ul>