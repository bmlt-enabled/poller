<?php
/**
 * Poll management screens.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private Repository $repo;
	private string $list_hook = '';
	private string $edit_hook = '';
	private string $error = '';

	/** @var array<string, mixed>|null */
	private ?array $old = null;

	public function __construct( Repository $repo ) {
		$this->repo = $repo;
	}

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_poller_delete_poll', [ $this, 'handle_delete' ] );
		add_action( 'admin_post_poller_set_status', [ $this, 'handle_status' ] );
		add_action( 'admin_post_poller_duplicate_poll', [ $this, 'handle_duplicate' ] );
		add_action( 'admin_post_poller_reset_poll', [ $this, 'handle_reset' ] );
		add_action( 'admin_post_poller_hide_word', [ $this, 'handle_hide_word' ] );
	}

	public function menu(): void {
		$this->list_hook = (string) add_menu_page(
			__( 'Poller', 'poller' ),
			__( 'Poller', 'poller' ),
			'manage_options',
			'poller',
			[ $this, 'render_list' ],
			'dashicons-forms',
			58
		);
		add_submenu_page(
			'poller',
			__( 'All polls', 'poller' ),
			__( 'All polls', 'poller' ),
			'manage_options',
			'poller',
			[ $this, 'render_list' ]
		);
		$this->edit_hook = (string) add_submenu_page(
			'poller',
			__( 'Add poll', 'poller' ),
			__( 'Add poll', 'poller' ),
			'manage_options',
			'poller-edit',
			[ $this, 'render_edit' ]
		);
		add_action( 'load-' . $this->edit_hook, [ $this, 'handle_save' ] );
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->list_hook && $hook !== $this->edit_hook ) {
			return;
		}

		wp_enqueue_style( 'poller-fonts', POLLER_URL . 'assets/css/fonts.css', [], POLLER_VERSION );
		wp_enqueue_style( 'poller-admin', POLLER_URL . 'assets/css/admin.css', [ 'poller-fonts' ], POLLER_VERSION );
		wp_enqueue_script( 'poller-admin', POLLER_URL . 'assets/js/admin.js', [], POLLER_VERSION, true );
		wp_localize_script(
			'poller-admin',
			'pollerAdmin',
			[
				'choose' => __( 'Choose a picture', 'poller' ),
				'use' => __( 'Use this picture', 'poller' ),
				'copied' => __( 'Copied', 'poller' ),
			]
		);

		if ( $hook === $this->edit_hook ) {
			wp_enqueue_media();
		}
	}

	public function handle_save(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		check_admin_referer( 'poller_save_poll' );
		$this->guard();

		$input = $this->posted_poll();
		$existing = null;
		if ( $input['id'] ) {
			$existing = $this->repo->get_poll( $input['id'] );
			if ( ! $existing ) {
				$this->error = __( 'That poll does not exist.', 'poller' );
				$this->old = $input;
				return;
			}
		}

		$locked = $existing && $this->repo->ballot_count( (int) $existing['id'] ) > 0;
		$error = $this->validate( $input, (bool) $locked );
		if ( null !== $error ) {
			$this->error = $error;
			$this->old = $input;
			return;
		}

		$fields = [
			'title' => $this->title_or_question( $input ),
			'question' => $input['question'],
			'image_id' => $input['image_id'],
			'status' => $input['status'],
		] + ( $locked ? $this->rule_fields( $existing ) : $this->rule_fields( $this->stored_rules( $input ) ) );

		if ( ! $existing ) {
			try {
				$fields['code'] = $this->repo->next_code();
			} catch ( \RuntimeException $e ) {
				$this->error = __( 'Could not make a poll code. Save again.', 'poller' );
				$this->old = $input;
				return;
			}
			$id = $this->repo->insert_poll( $fields );
			if ( ! $this->repo->replace_choices( $id, $this->choice_rows( $input ) ) ) {
				$this->repo->delete_poll( $id );
				$this->error = __( 'That poll did not save. Try again.', 'poller' );
				$this->old = $input;
				return;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=poller-edit&poll=' . $id . '&poller_notice=saved' ) );
			exit;
		}

		$id = (int) $existing['id'];
		$saved = $this->repo->save_poll( $id, $fields, $locked ? null : $this->choice_rows( $input ) );
		$notice = ( ! $locked && ! $saved ) ? 'kept' : 'saved';

		wp_safe_redirect( admin_url( 'admin.php?page=poller-edit&poll=' . $id . '&poller_notice=' . $notice ) );
		exit;
	}

	public function handle_delete(): void {
		$id = absint( $_POST['poll_id'] ?? 0 );
		check_admin_referer( 'poller_delete_poll_' . $id );
		$this->guard();
		if ( $this->repo->get_poll( $id ) ) {
			$this->repo->delete_poll( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=poller&poller_notice=deleted' ) );
		exit;
	}

	public function handle_status(): void {
		$id = absint( $_POST['poll_id'] ?? 0 );
		check_admin_referer( 'poller_status_' . $id );
		$this->guard();
		$status = ( isset( $_POST['status'] ) && 'closed' === $_POST['status'] ) ? 'closed' : 'open';
		if ( $this->repo->get_poll( $id ) ) {
			$this->repo->set_status( $id, $status );
		}
		$notice = 'closed' === $status ? 'closed' : 'opened';
		wp_safe_redirect( admin_url( 'admin.php?page=poller&poller_notice=' . $notice ) );
		exit;
	}

	/**
	 * Copy a poll's setup and choices into a new closed poll, then open the
	 * copy's edit screen.
	 */
	public function handle_duplicate(): void {
		$id = absint( $_POST['poll_id'] ?? 0 );
		check_admin_referer( 'poller_duplicate_poll_' . $id );
		$this->guard();
		$copy = $this->repo->duplicate_poll( $id );
		if ( null === $copy ) {
			wp_safe_redirect( admin_url( 'admin.php?page=poller&poller_notice=not_duplicated' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=poller-edit&poll=' . $copy . '&poller_notice=duplicated' ) );
		exit;
	}

	/**
	 * Clear every answer on a poll but keep the poll. Posted from the poll
	 * list or the edit screen, and goes back to the page it came from.
	 */
	public function handle_reset(): void {
		$id = absint( $_POST['poll_id'] ?? 0 );
		check_admin_referer( 'poller_reset_poll_' . $id );
		$this->guard();
		if ( ! $this->repo->reset_poll( $id ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=poller' ) );
			exit;
		}
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=poller' );
		}
		wp_safe_redirect( add_query_arg( 'poller_notice', 'reset', remove_query_arg( 'poller_notice', $back ) ) );
		exit;
	}

	/**
	 * Hide a word cloud entry, or show it again. Posted from the poll's edit
	 * screen or from the results on the public poll page.
	 */
	public function handle_hide_word(): void {
		$id = absint( $_POST['poll_id'] ?? 0 );
		check_admin_referer( 'poller_hide_word_' . $id );
		$this->guard();
		$poll = $this->repo->get_poll( $id );
		// BallotRules::word() cleans the text into the key it was counted under.
		// sanitize_text_field() would turn "<3" into "&lt;3" and miss that key.
		$word = isset( $_POST['word'] ) ? BallotRules::word( wp_unslash( $_POST['word'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$hide = isset( $_POST['hide'] ) && sanitize_key( wp_unslash( $_POST['hide'] ) ) === '1';
		if ( $poll && 'words' === $poll['selection'] && null !== $word ) {
			$this->repo->set_word_hidden( $id, $word['key'], $hide );
		}
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = $this->edit_url( $id );
		}
		wp_safe_redirect( add_query_arg( 'poller_notice', $hide ? 'hidden' : 'shown', remove_query_arg( 'poller_notice', $back ) ) );
		exit;
	}

	public function render_list(): void {
		$this->guard();
		$polls = $this->repo->list_polls();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Polls', 'poller' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=poller-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add poll', 'poller' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->notices(); ?>
			<?php if ( count( $polls ) === 0 ) : ?>
				<p><?php esc_html_e( 'No polls yet. Add a poll to get a code for the room.', 'poller' ); ?></p>
			<?php else : ?>
				<table class="widefat striped poller-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Poll', 'poller' ); ?></th>
							<th><?php esc_html_e( 'Code', 'poller' ); ?></th>
							<th><?php esc_html_e( 'Choices', 'poller' ); ?></th>
							<th><?php esc_html_e( 'Status', 'poller' ); ?></th>
							<th><?php esc_html_e( 'Votes', 'poller' ); ?></th>
							<th><?php esc_html_e( 'Created', 'poller' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $polls as $poll ) : ?>
							<tr>
								<td class="poller-admin-question">
									<strong><a href="<?php echo esc_url( $this->edit_url( (int) $poll['id'] ) ); ?>"><?php echo esc_html( $poll['title'] ); ?></a></strong>
									<?php if ( $poll['title'] !== $poll['question'] ) : ?>
										<div class="description"><?php echo esc_html( $poll['question'] ); ?></div>
									<?php endif; ?>
									<div class="row-actions">
										<span><a href="<?php echo esc_url( $this->edit_url( (int) $poll['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'poller' ); ?></a> | </span>
										<span><a href="<?php echo esc_url( Plugin::board_url( $poll['code'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Room display', 'poller' ); ?></a> | </span>
										<span><a href="<?php echo esc_url( Plugin::poll_url( $poll['code'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open poll', 'poller' ); ?></a> | </span>
										<span>
											<form class="poller-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php wp_nonce_field( 'poller_status_' . (int) $poll['id'] ); ?>
												<input type="hidden" name="action" value="poller_set_status">
												<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) $poll['id'] ); ?>">
												<input type="hidden" name="status" value="<?php echo 'open' === $poll['status'] ? 'closed' : 'open'; ?>">
												<button type="submit" class="button-link"><?php echo 'open' === $poll['status'] ? esc_html__( 'Close', 'poller' ) : esc_html__( 'Reopen', 'poller' ); ?></button>
											</form>
											|
										</span>
										<span>
											<form class="poller-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<?php wp_nonce_field( 'poller_duplicate_poll_' . (int) $poll['id'] ); ?>
												<input type="hidden" name="action" value="poller_duplicate_poll">
												<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) $poll['id'] ); ?>">
												<button type="submit" class="button-link"><?php esc_html_e( 'Duplicate', 'poller' ); ?></button>
											</form>
											|
										</span>
										<span>
											<?php $this->reset_form( (int) $poll['id'] ); ?>
											|
										</span>
										<span>
											<form class="poller-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-poller-confirm="<?php echo esc_attr__( 'Delete this poll and its votes?', 'poller' ); ?>">
												<?php wp_nonce_field( 'poller_delete_poll_' . (int) $poll['id'] ); ?>
												<input type="hidden" name="action" value="poller_delete_poll">
												<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) $poll['id'] ); ?>">
												<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'poller' ); ?></button>
											</form>
										</span>
									</div>
								</td>
								<td><span class="poller-admin-code-small"><?php echo esc_html( $poll['code'] ); ?></span></td>
								<td>
									<?php
									if ( 'words' !== $poll['selection'] ) {
										echo esc_html( 'image' === $poll['kind'] ? __( 'Pictures', 'poller' ) : __( 'Text', 'poller' ) );
										echo esc_html( ', ' );
									}
									echo esc_html( $this->selection_label( $poll ) );
									?>
								</td>
								<td><?php echo 'open' === $poll['status'] ? esc_html__( 'Open', 'poller' ) : esc_html__( 'Closed', 'poller' ); ?></td>
								<td><?php echo esc_html( (string) (int) $poll['ballots'] ); ?></td>
								<td><?php echo esc_html( $this->local_time( (string) $poll['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_edit(): void {
		$this->guard();

		if ( $this->old ) {
			$input = $this->old;
			$poll = $input['id'] ? $this->repo->get_poll( (int) $input['id'] ) : null;
		} else {
			$id = isset( $_GET['poll'] ) ? absint( wp_unslash( $_GET['poll'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$poll = $id ? $this->repo->get_poll( $id ) : null;
			if ( $id && ! $poll ) {
				wp_die( esc_html__( 'That poll does not exist.', 'poller' ), '', [ 'response' => 404 ] );
			}
			$input = $poll ? $this->input_from_poll( $poll ) : $this->blank_input();
		}

		$locked = $poll && $this->repo->ballot_count( (int) $poll['id'] ) > 0;
		$choices = $input['choices'];
		if ( ! $locked ) {
			while ( count( $choices ) < 2 ) {
				$choices[] = [
					'label' => '',
					'image_id' => 0,
				];
			}
		}
		$prompt = $this->image_preview( (int) $input['image_id'] );
		?>
		<div class="wrap">
			<h1><?php echo $poll ? esc_html__( 'Edit poll', 'poller' ) : esc_html__( 'Add poll', 'poller' ); ?></h1>
			<?php $this->notices(); ?>
			<?php if ( '' !== $this->error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $this->error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $poll ) : ?>
				<p class="poller-admin-code" id="poller-code-text"><?php echo esc_html( $poll['code'] ); ?></p>
				<p>
					<button type="button" class="button" data-poller-copy="poller-code-text"><?php esc_html_e( 'Copy code', 'poller' ); ?></button>
					<a class="button" href="<?php echo esc_url( Plugin::board_url( $poll['code'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Room display', 'poller' ); ?></a>
					<a class="button" href="<?php echo esc_url( Plugin::poll_url( $poll['code'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open poll', 'poller' ); ?></a>
				</p>
				<p>
					<label for="poller-link"><?php esc_html_e( 'Poll link', 'poller' ); ?></label><br>
					<input type="text" id="poller-link" class="large-text" readonly value="<?php echo esc_attr( Plugin::poll_url( $poll['code'] ) ); ?>">
					<button type="button" class="button" data-poller-copy="poller-link"><?php esc_html_e( 'Copy link', 'poller' ); ?></button>
				</p>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
						/* translators: %s is the page where someone types a poll code. */
							__( 'People can scan the room display, or open %s and enter the code.', 'poller' ),
							$this->human_join()
						)
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=poller-edit' ) ); ?>">
				<?php wp_nonce_field( 'poller_save_poll' ); ?>
				<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) (int) $input['id'] ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="poller-title"><?php esc_html_e( 'Name', 'poller' ); ?></label></th>
						<td>
							<input type="text" id="poller-title" name="title" class="regular-text" maxlength="160" value="<?php echo esc_attr( $input['title'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown only to you, so you can tell polls apart.', 'poller' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="poller-question"><?php esc_html_e( 'Question', 'poller' ); ?></label></th>
						<td>
							<textarea id="poller-question" name="question" class="large-text" rows="3" maxlength="500" required><?php echo esc_textarea( $input['question'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Picture', 'poller' ); ?></th>
						<td>
							<input type="hidden" id="poller-image-id" name="image_id" value="<?php echo esc_attr( (string) (int) $input['image_id'] ); ?>">
							<span id="poller-prompt-preview">
								<?php if ( '' !== $prompt ) : ?>
									<img src="<?php echo esc_url( $prompt ); ?>" alt="">
								<?php endif; ?>
							</span>
							<button type="button" class="button" id="poller-pick-prompt"><?php esc_html_e( 'Choose picture', 'poller' ); ?></button>
							<button type="button" class="button-link" id="poller-clear-prompt" <?php echo '' === $prompt ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove picture', 'poller' ); ?></button>
							<p class="description"><?php esc_html_e( 'Optional. Shown with the question, for a poll about an image.', 'poller' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Choices', 'poller' ); ?></th>
						<td>
							<?php if ( $locked && 'words' === $poll['selection'] ) : ?>
								<p><?php echo esc_html( ucfirst( $this->selection_label( $poll ) ) ); ?></p>
								<p class="description"><?php esc_html_e( 'People have already answered, so the voting rules stay as they are. You can still edit the question, the picture, and whether the poll is open.', 'poller' ); ?></p>
								<p><?php $this->reset_button( __( 'Reset votes to change the rules', 'poller' ) ); ?></p>
							<?php elseif ( $locked ) : ?>
								<p><?php echo esc_html( 'image' === $input['kind'] ? __( 'Picture choices', 'poller' ) : __( 'Text choices', 'poller' ) ); ?>,
									<?php echo esc_html( $this->selection_label( $poll ) ); ?></p>
								<ul class="poller-admin-locked">
									<?php foreach ( $choices as $choice ) : ?>
										<li>
											<?php $thumb = 'image' === $input['kind'] ? $this->image_preview( (int) $choice['image_id'] ) : ''; ?>
											<?php if ( '' !== $thumb ) : ?>
												<img src="<?php echo esc_url( $thumb ); ?>" alt="">
											<?php endif; ?>
											<?php echo esc_html( '' !== $choice['label'] ? $choice['label'] : __( 'Picture', 'poller' ) ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
								<p class="description"><?php esc_html_e( 'People have already voted, so the choices and voting rules stay as they are. You can still edit the question, the picture, and whether the poll is open.', 'poller' ); ?></p>
								<p><?php $this->reset_button( __( 'Reset votes to change the rules', 'poller' ) ); ?></p>
							<?php else : ?>
								<fieldset id="poller-kind">
									<legend class="screen-reader-text"><?php esc_html_e( 'Choice type', 'poller' ); ?></legend>
									<label><input type="radio" name="kind" value="text" <?php checked( $input['kind'], 'text' ); ?>> <?php esc_html_e( 'Text', 'poller' ); ?></label>
									&nbsp;
									<label><input type="radio" name="kind" value="image" <?php checked( $input['kind'], 'image' ); ?>> <?php esc_html_e( 'Pictures', 'poller' ); ?></label>
								</fieldset>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'How many answers', 'poller' ); ?></legend>
									<label><input type="radio" name="selection" value="single" <?php checked( $input['selection'], 'single' ); ?>> <?php esc_html_e( 'One choice', 'poller' ); ?></label>
									&nbsp;
									<label><input type="radio" name="selection" value="multi" <?php checked( $input['selection'], 'multi' ); ?>> <?php esc_html_e( 'More than one', 'poller' ); ?></label>
									&nbsp;
									<label><input type="radio" name="selection" value="dots" <?php checked( $input['selection'], 'dots' ); ?>> <?php esc_html_e( 'Dot voting', 'poller' ); ?></label>
									&nbsp;
									<label><input type="radio" name="selection" value="words" <?php checked( $input['selection'], 'words' ); ?>> <?php esc_html_e( 'Word cloud', 'poller' ); ?></label>
								</fieldset>
								<fieldset class="poller-admin-dots" id="poller-words-settings">
									<legend><?php esc_html_e( 'Word cloud', 'poller' ); ?></legend>
									<p class="description"><?php esc_html_e( 'Each person types a few words or short phrases. The room display shows them as a cloud, larger when more people entered the same word. There are no choices to set up.', 'poller' ); ?></p>
									<p>
										<label for="poller-word-limit"><?php esc_html_e( 'Words for each person', 'poller' ); ?></label>
										<input type="number" id="poller-word-limit" name="word_limit" class="small-text" min="1" max="<?php echo esc_attr( (string) BallotRules::MAX_WORDS ); ?>" value="<?php echo esc_attr( (string) (int) $input['word_limit'] ); ?>">
									</p>
								</fieldset>
								<fieldset class="poller-admin-dots" id="poller-dots-settings">
									<legend><?php esc_html_e( 'Dot voting', 'poller' ); ?></legend>
									<p class="description"><?php esc_html_e( 'Each person spreads a set number of dots across the items.', 'poller' ); ?></p>
									<p>
										<label for="poller-budget"><?php esc_html_e( 'Dots for each person', 'poller' ); ?></label>
										<input type="number" id="poller-budget" name="budget" class="small-text" min="1" max="<?php echo esc_attr( (string) BallotRules::MAX_BUDGET ); ?>" value="<?php echo esc_attr( (string) (int) $input['budget'] ); ?>">
									</p>
									<p>
										<label><input type="checkbox" id="poller-stacking" name="stacking" value="1" <?php checked( $input['stacking'] ); ?>> <?php esc_html_e( 'Allow more than one dot on an item', 'poller' ); ?></label>
									</p>
									<p id="poller-per-choice-row">
										<label for="poller-per-choice"><?php esc_html_e( 'Most dots on one item', 'poller' ); ?></label>
										<input type="number" id="poller-per-choice" name="per_choice" class="small-text" min="2" max="<?php echo esc_attr( (string) BallotRules::MAX_BUDGET ); ?>" value="<?php echo esc_attr( (string) (int) $input['per_choice'] ); ?>">
									</p>
									<p>
										<label><input type="checkbox" name="spend_all" value="1" <?php checked( $input['spend_all'] ); ?>> <?php esc_html_e( 'People must use every dot', 'poller' ); ?></label>
									</p>
								</fieldset>
								<div id="poller-choice-setup">
								<div id="poller-choices" data-kind="<?php echo esc_attr( $input['kind'] ); ?>" data-max="<?php echo esc_attr( (string) BallotRules::max_choices( $input['selection'] ) ); ?>" data-max-single="<?php echo esc_attr( (string) BallotRules::MAX_CHOICES ); ?>" data-max-dots="<?php echo esc_attr( (string) BallotRules::MAX_DOT_CHOICES ); ?>" data-placeholder-text="<?php echo esc_attr__( 'Choice', 'poller' ); ?>" data-placeholder-image="<?php echo esc_attr__( 'Caption, optional', 'poller' ); ?>">
									<?php foreach ( $choices as $choice ) : ?>
										<?php $thumb = $this->image_preview( (int) $choice['image_id'] ); ?>
										<div class="poller-admin-choice">
											<input type="text" class="regular-text" name="choice_label[]" maxlength="160" value="<?php echo esc_attr( $choice['label'] ); ?>" placeholder="<?php echo esc_attr( 'image' === $input['kind'] ? __( 'Caption, optional', 'poller' ) : __( 'Choice', 'poller' ) ); ?>">
											<input type="hidden" class="poller-choice-image-id" name="choice_image[]" value="<?php echo esc_attr( (string) (int) $choice['image_id'] ); ?>">
											<button type="button" class="button poller-pick-choice"><?php esc_html_e( 'Picture', 'poller' ); ?></button>
											<button type="button" class="button-link-delete poller-remove-choice"><?php esc_html_e( 'Remove', 'poller' ); ?></button>
											<span class="poller-choice-preview">
											<?php
											if ( '' !== $thumb ) :
												?>
												<img src="<?php echo esc_url( $thumb ); ?>" alt=""><?php endif; ?></span>
										</div>
									<?php endforeach; ?>
								</div>
								<p><button type="button" class="button" id="poller-add-choice"><?php esc_html_e( 'Add choice', 'poller' ); ?></button></p>
								<p>
									<label for="poller-choice-list"><?php esc_html_e( 'Paste a list', 'poller' ); ?></label><br>
									<textarea id="poller-choice-list" name="choice_list" class="large-text" rows="5"></textarea>
								</p>
								<p class="description"><?php esc_html_e( 'One choice per line. They are added after the choices above when you save.', 'poller' ); ?></p>
								<template id="poller-choice-template">
									<div class="poller-admin-choice">
										<input type="text" class="regular-text" name="choice_label[]" maxlength="160" value="" placeholder="<?php echo esc_attr__( 'Choice', 'poller' ); ?>">
										<input type="hidden" class="poller-choice-image-id" name="choice_image[]" value="0">
										<button type="button" class="button poller-pick-choice"><?php esc_html_e( 'Picture', 'poller' ); ?></button>
										<button type="button" class="button-link-delete poller-remove-choice"><?php esc_html_e( 'Remove', 'poller' ); ?></button>
										<span class="poller-choice-preview"></span>
									</div>
								</template>
								</div>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="poller-status"><?php esc_html_e( 'Status', 'poller' ); ?></label></th>
						<td>
							<select id="poller-status" name="status">
								<option value="open" <?php selected( $input['status'], 'open' ); ?>><?php esc_html_e( 'Open', 'poller' ); ?></option>
								<option value="closed" <?php selected( $input['status'], 'closed' ); ?>><?php esc_html_e( 'Closed', 'poller' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( $poll ? __( 'Save poll', 'poller' ) : __( 'Create poll', 'poller' ) ); ?>
			</form>
			<?php
			if ( $locked ) {
				// Forms cannot nest, so the reset button inside the poll form submits this one.
				$this->reset_form( (int) $poll['id'], 'poller-reset-form' );
			}
			?>
			<?php if ( $poll && 'words' === $poll['selection'] ) : ?>
				<h2 id="poller-words"><?php esc_html_e( 'Words', 'poller' ); ?></h2>
				<?php $this->render_words( $poll ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Reset votes form. With an id, the form has no button of its own
	 * and reset_button() submits it from elsewhere on the page.
	 */
	private function reset_form( int $poll_id, string $id = '' ): void {
		?>
		<form class="poller-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-poller-confirm="<?php echo esc_attr__( 'Clear every answer on this poll? The poll, its code and its choices stay.', 'poller' ); ?>"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
			<?php wp_nonce_field( 'poller_reset_poll_' . $poll_id ); ?>
			<input type="hidden" name="action" value="poller_reset_poll">
			<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) $poll_id ); ?>">
			<?php if ( '' === $id ) : ?>
				<button type="submit" class="button-link-delete"><?php esc_html_e( 'Reset votes', 'poller' ); ?></button>
			<?php endif; ?>
		</form>
		<?php
	}

	private function reset_button( string $label ): void {
		echo '<button type="submit" class="button-link" form="poller-reset-form">' . esc_html( $label ) . '</button>';
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage polls.', 'poller' ), '', [ 'response' => 403 ] );
		}
	}

	private function notices(): void {
		// Read-only status from an admin redirect. The value is matched against a fixed list.
		if ( ! isset( $_GET['poller_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['poller_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = [
			'saved' => __( 'Poll saved.', 'poller' ),
			'deleted' => __( 'Poll deleted.', 'poller' ),
			'closed' => __( 'Poll closed.', 'poller' ),
			'opened' => __( 'Poll opened.', 'poller' ),
			'duplicated' => __( 'Poll duplicated.', 'poller' ),
			'reset' => __( 'Votes reset. The poll is empty again.', 'poller' ),
			'not_duplicated' => __( 'That poll could not be duplicated. Try again.', 'poller' ),
			'kept' => __( 'People voted while you were editing, so the choices were kept.', 'poller' ),
			'hidden' => __( 'Word hidden.', 'poller' ),
			'shown' => __( 'Word shown again.', 'poller' ),
		];
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		$class = in_array( $key, [ 'kept', 'not_duplicated' ], true ) ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $map[ $key ] ) . '</p></div>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function posted_poll(): array {
		check_admin_referer( 'poller_save_poll' );

		$labels = ( isset( $_POST['choice_label'] ) && is_array( $_POST['choice_label'] ) )
			? map_deep( wp_unslash( $_POST['choice_label'] ), 'sanitize_text_field' )
			: [];
		$images = ( isset( $_POST['choice_image'] ) && is_array( $_POST['choice_image'] ) )
			? map_deep( wp_unslash( $_POST['choice_image'] ), 'absint' )
			: [];
		$count = max( count( $labels ), count( $images ) );
		$choices = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$label_raw = $labels[ $i ] ?? '';
			$image_raw = $images[ $i ] ?? 0;
			if ( ! is_scalar( $label_raw ) || ! is_scalar( $image_raw ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) $label_raw );
			$image_id = absint( $image_raw );
			if ( '' === $label && 0 === $image_id ) {
				continue;
			}
			$choices[] = [
				'label' => $label,
				'image_id' => $image_id,
			];
		}

		$pasted = isset( $_POST['choice_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['choice_list'] ) ) : '';
		$lines = preg_split( '/\R/', $pasted );
		if ( false === $lines ) {
			$lines = [];
		}
		foreach ( $lines as $line ) {
			$label = sanitize_text_field( $line );
			if ( '' !== $label ) {
				$choices[] = [
					'label' => $label,
					'image_id' => 0,
				];
			}
		}

		$kind = ( isset( $_POST['kind'] ) && sanitize_key( wp_unslash( $_POST['kind'] ) ) === 'image' ) ? 'image' : 'text';
		$selection = isset( $_POST['selection'] ) ? sanitize_key( wp_unslash( $_POST['selection'] ) ) : '';
		if ( ! in_array( $selection, [ 'single', 'multi', 'dots', 'words' ], true ) ) {
			$selection = 'single';
		}
		$budget = isset( $_POST['budget'] ) ? absint( wp_unslash( $_POST['budget'] ) ) : BallotRules::DEFAULT_BUDGET;
		$stacking = ! empty( $_POST['stacking'] );
		$per_choice = isset( $_POST['per_choice'] ) ? absint( wp_unslash( $_POST['per_choice'] ) ) : $budget;
		$word_limit = isset( $_POST['word_limit'] ) ? absint( wp_unslash( $_POST['word_limit'] ) ) : BallotRules::DEFAULT_WORDS;
		$status = ( isset( $_POST['status'] ) && sanitize_key( wp_unslash( $_POST['status'] ) ) === 'closed' ) ? 'closed' : 'open';

		return [
			'id' => isset( $_POST['poll_id'] ) ? absint( wp_unslash( $_POST['poll_id'] ) ) : 0,
			'title' => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'question' => isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '',
			'image_id' => isset( $_POST['image_id'] ) ? absint( wp_unslash( $_POST['image_id'] ) ) : 0,
			'kind' => $kind,
			'selection' => $selection,
			'budget' => $budget,
			'stacking' => $stacking,
			'per_choice' => $per_choice,
			'spend_all' => ! empty( $_POST['spend_all'] ),
			'word_limit' => $word_limit,
			'status' => $status,
			'choices' => $choices,
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function validate( array $input, bool $locked ): ?string {
		if ( '' === $input['question'] ) {
			return __( 'Write the question people will see.', 'poller' );
		}
		if ( $this->length( $input['question'] ) > 500 ) {
			return __( 'Keep the question under 500 characters.', 'poller' );
		}
		if ( $this->length( $input['title'] ) > 160 ) {
			return __( 'Keep the name under 160 characters.', 'poller' );
		}
		if ( $input['image_id'] && ! wp_attachment_is_image( $input['image_id'] ) ) {
			return __( 'The question picture needs to be an image.', 'poller' );
		}
		if ( $locked ) {
			return null;
		}
		if ( 'words' === $input['selection'] ) {
			$limit = (int) $input['word_limit'];
			if ( $limit < 1 || $limit > BallotRules::MAX_WORDS ) {
				return sprintf(
					/* translators: %d is the most words a person can enter. */
					__( 'Let each person enter from 1 to %d words.', 'poller' ),
					BallotRules::MAX_WORDS
				);
			}
			return null;
		}
		if ( count( $input['choices'] ) < 2 ) {
			return __( 'Add at least two choices.', 'poller' );
		}
		$max = BallotRules::max_choices( $input['selection'] );
		if ( count( $input['choices'] ) > $max ) {
			return sprintf(
				/* translators: %d is the most choices a poll can have. */
				__( 'A poll like this can have up to %d choices.', 'poller' ),
				$max
			);
		}
		if ( 'dots' === $input['selection'] ) {
			$error = $this->validate_dots( $input );
			if ( null !== $error ) {
				return $error;
			}
		}
		foreach ( $input['choices'] as $choice ) {
			if ( $this->length( $choice['label'] ) > 160 ) {
				return __( 'Keep each choice under 160 characters.', 'poller' );
			}
			if ( 'text' === $input['kind'] && '' === $choice['label'] ) {
				return __( 'Each choice needs a label.', 'poller' );
			}
			if ( 'image' === $input['kind'] && ( ! $choice['image_id'] || ! wp_attachment_is_image( $choice['image_id'] ) ) ) {
				return __( 'Each picture choice needs an image.', 'poller' );
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function validate_dots( array $input ): ?string {
		$budget = (int) $input['budget'];
		if ( $budget < 1 || $budget > BallotRules::MAX_BUDGET ) {
			return sprintf(
				/* translators: %d is the most dots a person can have. */
				__( 'Give each person from 1 to %d dots.', 'poller' ),
				BallotRules::MAX_BUDGET
			);
		}
		$rules = $this->stored_rules( $input );
		if ( $input['stacking'] && $budget > 1 && ( $input['per_choice'] < 2 || $input['per_choice'] > $budget ) ) {
			return sprintf(
				/* translators: %d is how many dots each person has. */
				__( 'Set the most dots on one item from 2 to %d.', 'poller' ),
				$budget
			);
		}
		if ( $rules['spend_all'] && count( $input['choices'] ) * $rules['per_choice'] < $budget ) {
			return __( 'There is no way to use every dot with these items. Add items, allow more dots on one item, or turn off "People must use every dot".', 'poller' );
		}
		return null;
	}

	/**
	 * Voting rules as they are stored. With stacking off, an item takes 1 dot.
	 * A word cloud keeps its words per person in budget. Other polls store zeros.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function stored_rules( array $input ): array {
		$rules = [
			'kind' => $input['kind'],
			'selection' => $input['selection'],
			'budget' => 0,
			'per_choice' => 0,
			'spend_all' => false,
		];
		if ( 'words' === $input['selection'] ) {
			$rules['kind'] = 'text';
			$rules['budget'] = (int) $input['word_limit'];
			return $rules;
		}
		if ( 'dots' !== $input['selection'] ) {
			return $rules;
		}
		$budget = (int) $input['budget'];
		$rules['budget'] = $budget;
		$rules['per_choice'] = $input['stacking'] ? max( 1, min( (int) $input['per_choice'], $budget ) ) : 1;
		$rules['spend_all'] = (bool) $input['spend_all'];
		return $rules;
	}

	/**
	 * @param array<string, mixed> $poll
	 * @return array<string, mixed>
	 */
	private function rule_fields( array $poll ): array {
		return [
			'kind' => (string) $poll['kind'],
			'selection' => (string) $poll['selection'],
			'budget' => (int) $poll['budget'],
			'per_choice' => (int) $poll['per_choice'],
			'spend_all' => ! empty( $poll['spend_all'] ),
		];
	}

	/**
	 * @param array<string, mixed> $poll
	 */
	private function selection_label( array $poll ): string {
		if ( 'multi' === $poll['selection'] ) {
			return __( 'more than one', 'poller' );
		}
		if ( 'words' === $poll['selection'] ) {
			$limit = (int) $poll['budget'];
			return sprintf(
				/* translators: %d is how many words each person can enter. */
				_n( 'word cloud, %d word each', 'word cloud, up to %d words each', $limit, 'poller' ),
				$limit
			);
		}
		if ( 'dots' !== $poll['selection'] ) {
			return __( 'one', 'poller' );
		}
		$budget = (int) $poll['budget'];
		$label = sprintf(
			/* translators: %d is how many dots each person has. */
			_n( 'dot voting, %d dot', 'dot voting, %d dots', $budget, 'poller' ),
			$budget
		);
		if ( (int) $poll['per_choice'] > 1 ) {
			$label .= ', ' . sprintf(
				/* translators: %d is the most dots on one item. */
				__( 'up to %d on one item', 'poller' ),
				(int) $poll['per_choice']
			);
		}
		if ( ! empty( $poll['spend_all'] ) ) {
			$label .= ', ' . __( 'use every dot', 'poller' );
		}
		return $label;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<int, array{label: string, image_id: int}>
	 */
	private function choice_rows( array $input ): array {
		$rows = [];
		if ( 'words' === $input['selection'] ) {
			return $rows;
		}
		foreach ( $input['choices'] as $choice ) {
			$rows[] = [
				'label' => $choice['label'],
				'image_id' => 'image' === $input['kind'] ? (int) $choice['image_id'] : 0,
			];
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function title_or_question( array $input ): string {
		if ( '' !== $input['title'] ) {
			return $input['title'];
		}
		$flat = trim( (string) preg_replace( '/\s+/', ' ', $input['question'] ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $flat, 0, 80 );
		}
		return substr( $flat, 0, 80 );
	}

	/**
	 * @param array<string, mixed> $poll
	 * @return array<string, mixed>
	 */
	private function input_from_poll( array $poll ): array {
		$choices = [];
		foreach ( $this->repo->get_choices( (int) $poll['id'] ) as $choice ) {
			$choices[] = [
				'label' => $choice['label'],
				'image_id' => $choice['image_id'],
			];
		}
		$dots = 'dots' === $poll['selection'];
		return [
			'id' => (int) $poll['id'],
			'title' => (string) $poll['title'],
			'question' => (string) $poll['question'],
			'image_id' => (int) $poll['image_id'],
			'kind' => (string) $poll['kind'],
			'selection' => (string) $poll['selection'],
			'budget' => $dots && (int) $poll['budget'] > 0 ? (int) $poll['budget'] : BallotRules::DEFAULT_BUDGET,
			'stacking' => (int) $poll['per_choice'] > 1,
			'per_choice' => (int) $poll['per_choice'] > 1 ? (int) $poll['per_choice'] : max( 2, $dots ? (int) $poll['budget'] : BallotRules::DEFAULT_BUDGET ),
			'spend_all' => ! empty( $poll['spend_all'] ),
			'word_limit' => 'words' === $poll['selection'] && (int) $poll['budget'] > 0 ? (int) $poll['budget'] : BallotRules::DEFAULT_WORDS,
			'status' => (string) $poll['status'],
			'choices' => $choices,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blank_input(): array {
		return [
			'id' => 0,
			'title' => '',
			'question' => '',
			'image_id' => 0,
			'kind' => 'text',
			'selection' => 'single',
			'budget' => BallotRules::DEFAULT_BUDGET,
			'stacking' => false,
			'per_choice' => BallotRules::DEFAULT_BUDGET,
			'spend_all' => false,
			'word_limit' => BallotRules::DEFAULT_WORDS,
			'status' => 'open',
			'choices' => [],
		];
	}

	/**
	 * Every word entered on a word cloud poll, with a Hide or Show button.
	 * Rendered after the poll form, since each button is its own small form.
	 *
	 * @param array<string, mixed> $poll
	 */
	private function render_words( array $poll ): void {
		$id = (int) $poll['id'];
		$hidden = $this->repo->hidden_words( $id );
		$words = Tally::words( $this->repo->word_rows( $id ), [], 0 );
		if ( ! $words && ! $hidden ) {
			echo '<p class="description">' . esc_html__( 'No words yet.', 'poller' ) . '</p>';
			return;
		}
		$is_hidden = array_fill_keys( $hidden, true );
		$seen = [];
		echo '<p class="description">' . esc_html__( 'Hide a word to take it off the room display and the results. People who entered it still see it in their own answers.', 'poller' ) . '</p>';
		echo '<table class="widefat striped poller-admin-words"><thead><tr><th>' . esc_html__( 'Word', 'poller' ) . '</th><th>' . esc_html__( 'People', 'poller' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Action', 'poller' ) . '</span></th></tr></thead><tbody>';
		foreach ( $words as $word ) {
			$seen[ $word['key'] ] = true;
			$this->word_row( $id, $word['text'], (int) $word['count'], isset( $is_hidden[ $word['key'] ] ) );
		}
		foreach ( $hidden as $key ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$this->word_row( $id, $key, 0, true );
			}
		}
		echo '</tbody></table>';
	}

	private function word_row( int $poll_id, string $text, int $count, bool $hidden ): void {
		?>
		<tr<?php echo $hidden ? ' class="poller-word-hidden"' : ''; ?>>
			<td><?php echo esc_html( $text ); ?>
			<?php
			if ( $hidden ) :
				?>
				<em><?php esc_html_e( '(hidden)', 'poller' ); ?></em><?php endif; ?></td>
			<td><?php echo esc_html( (string) $count ); ?></td>
			<td>
				<form class="poller-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'poller_hide_word_' . $poll_id ); ?>
					<input type="hidden" name="action" value="poller_hide_word">
					<input type="hidden" name="poll_id" value="<?php echo esc_attr( (string) $poll_id ); ?>">
					<input type="hidden" name="word" value="<?php echo esc_attr( $text ); ?>">
					<input type="hidden" name="hide" value="<?php echo $hidden ? '0' : '1'; ?>">
					<button type="submit" class="button-link"><?php echo $hidden ? esc_html__( 'Show again', 'poller' ) : esc_html__( 'Hide', 'poller' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	private function image_preview( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		return $url ? $url : '';
	}

	private function edit_url( int $id ): string {
		return admin_url( 'admin.php?page=poller-edit&poll=' . $id );
	}

	private function local_time( string $gmt ): string {
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	private function human_join(): string {
		$human = preg_replace( '#^https?://#', '', untrailingslashit( Plugin::join_url() ) );
		return $human ? $human : Plugin::join_url();
	}

	private function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}
}
