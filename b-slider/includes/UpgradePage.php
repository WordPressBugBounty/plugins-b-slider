<?php
namespace BSB\Posts;

if ( !defined( 'ABSPATH' ) ) { exit; }

class UpgradePage{
	public function __construct(){
		add_action( 'admin_menu', [$this, 'adminMenu'] );
	}

	function adminMenu(){
		add_submenu_page(
            'b-slider',
			__( 'B Slider - Upgrade', 'slider' ),
			__( 'Upgrade', 'slider' ),
			'manage_options',
			'edit.php?post_type=bsb',
			[$this, 'upgradePage']
		);
	}

	function upgradePage(){ ?>
		<iframe src='https://checkout.freemius.com/plugin/19318/plan/32001/' width='100%' frameborder='0' style='width: calc(100% - 20px); height: calc(100vh - 60px); margin-top: 15px;'></iframe>
	<?php }
}
new UpgradePage;