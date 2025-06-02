<?php
// Example author data — you can add more authors or change details here
$authors = [
    [
        'name'       => 'Your Name',
        'image'      => 'https://avatars.githubusercontent.com/u/0000000?v=4', // Replace with your actual GitHub avatar URL or any image URL
        'github'     => 'https://github.com/your-github-username',
        'social'     => [
            'twitter'  => 'https://twitter.com/yourtwitter',
            'linkedin' => 'https://linkedin.com/in/yourlinkedin',
            'facebook' => 'https://facebook.com/yourfacebook',
        ],
    ],
    // Add more authors here if needed
];
?>
<div class="wrap">
    <h1>Author</h1>
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:20px; margin-top:20px;">
        <?php foreach ($authors as $author): ?>
            <div style="border:1px solid #ddd; border-radius:8px; padding:15px; text-align:center; box-shadow:0 2px 5px rgba(0,0,0,0.1); background:#fff;">
                <img src="<?php echo esc_url($author['image']); ?>" alt="<?php echo esc_attr($author['name']); ?>" style="width:100px; height:100px; object-fit:cover; border-radius:50%; margin-bottom:15px;">
                <h2 style="font-size:18px; margin-bottom:10px;"><?php echo esc_html($author['name']); ?></h2>
                <a href="<?php echo esc_url($author['github']); ?>" target="_blank" style="display:inline-block; padding:8px 16px; background:#24292e; color:#fff; border-radius:4px; text-decoration:none; font-weight:bold; margin-bottom:10px;">
                    Visit GitHub
                </a>
                <div>
                    <?php if (!empty($author['social'])): ?>
                        <?php foreach ($author['social'] as $key => $url): ?>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" style="margin:0 5px; text-decoration:none; font-size:20px; color:#555;">
                                <?php
                                switch ($key) {
                                    case 'twitter': echo '🐦'; break;
                                    case 'linkedin': echo '🔗'; break;
                                    case 'facebook': echo '📘'; break;
                                    default: echo '🔗';
                                }
                                ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
