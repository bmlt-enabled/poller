<?php
/**
 * Poll management screens.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    private Repository $repo;
    private string $list_hook = '';
    private string $edit_hook = '';
    private string $error = '';

    /** @var array<string, mixed>|null */
    private ?array $old = null;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_poller_delete_poll', [$this, 'handle_delete']);
        add_action('admin_post_poller_set_status', [$this, 'handle_status']);
    }

    public function menu(): void
    {
        $this->list_hook = (string) add_menu_page(
            __('Poller', 'poller'),
            __('Poller', 'poller'),
            'manage_options',
            'poller',
            [$this, 'render_list'],
            'dashicons-forms',
            58
        );
        add_submenu_page(
            'poller',
            __('All polls', 'poller'),
            __('All polls', 'poller'),
            'manage_options',
            'poller',
            [$this, 'render_list']
        );
        $this->edit_hook = (string) add_submenu_page(
            'poller',
            __('Add poll', 'poller'),
            __('Add poll', 'poller'),
            'manage_options',
            'poller-edit',
            [$this, 'render_edit']
        );
        add_action('load-' . $this->edit_hook, [$this, 'handle_save']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->list_hook && $hook !== $this->edit_hook) {
            return;
        }

        wp_enqueue_style('poller-fonts', POLLER_URL . 'assets/css/fonts.css', [], POLLER_VERSION);
        wp_enqueue_style('poller-admin', POLLER_URL . 'assets/css/admin.css', ['poller-fonts'], POLLER_VERSION);
        wp_enqueue_script('poller-admin', POLLER_URL . 'assets/js/admin.js', [], POLLER_VERSION, true);
        wp_localize_script('poller-admin', 'pollerAdmin', [
            'choose' => __('Choose a picture', 'poller'),
            'use' => __('Use this picture', 'poller'),
            'copied' => __('Copied', 'poller'),
        ]);

        if ($hook === $this->edit_hook) {
            wp_enqueue_media();
        }
    }

    public function handle_save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        check_admin_referer('poller_save_poll');
        $this->guard();

        $input = $this->posted_poll();
        $existing = null;
        if ($input['id']) {
            $existing = $this->repo->get_poll($input['id']);
            if (!$existing) {
                $this->error = __('That poll does not exist.', 'poller');
                $this->old = $input;
                return;
            }
        }

        $locked = $existing && $this->repo->ballot_count((int) $existing['id']) > 0;
        $error = $this->validate($input, (bool) $locked);
        if ($error !== null) {
            $this->error = $error;
            $this->old = $input;
            return;
        }

        $fields = [
            'title' => $this->title_or_question($input),
            'question' => $input['question'],
            'image_id' => $input['image_id'],
            'kind' => $locked ? (string) $existing['kind'] : $input['kind'],
            'selection' => $locked ? (string) $existing['selection'] : $input['selection'],
            'status' => $input['status'],
        ];

        if (!$existing) {
            try {
                $fields['code'] = $this->repo->next_code();
            } catch (\RuntimeException $e) {
                $this->error = __('Could not make a poll code. Save again.', 'poller');
                $this->old = $input;
                return;
            }
            $id = $this->repo->insert_poll($fields);
            if (!$this->repo->replace_choices($id, $this->choice_rows($input))) {
                $this->repo->delete_poll($id);
                $this->error = __('That poll did not save. Try again.', 'poller');
                $this->old = $input;
                return;
            }
            wp_safe_redirect(admin_url('admin.php?page=poller-edit&poll=' . $id . '&poller_notice=saved'));
            exit;
        }

        $id = (int) $existing['id'];
        $notice = 'saved';
        if (!$locked && !$this->repo->replace_choices($id, $this->choice_rows($input))) {
            $fields['kind'] = (string) $existing['kind'];
            $fields['selection'] = (string) $existing['selection'];
            $notice = 'kept';
        }
        $this->repo->update_poll($id, $fields);

        wp_safe_redirect(admin_url('admin.php?page=poller-edit&poll=' . $id . '&poller_notice=' . $notice));
        exit;
    }

    public function handle_delete(): void
    {
        $id = absint($_POST['poll_id'] ?? 0);
        check_admin_referer('poller_delete_poll_' . $id);
        $this->guard();
        if ($this->repo->get_poll($id)) {
            $this->repo->delete_poll($id);
        }
        wp_safe_redirect(admin_url('admin.php?page=poller&poller_notice=deleted'));
        exit;
    }

    public function handle_status(): void
    {
        $id = absint($_POST['poll_id'] ?? 0);
        check_admin_referer('poller_status_' . $id);
        $this->guard();
        $status = (isset($_POST['status']) && $_POST['status'] === 'closed') ? 'closed' : 'open';
        if ($this->repo->get_poll($id)) {
            $this->repo->set_status($id, $status);
        }
        $notice = $status === 'closed' ? 'closed' : 'opened';
        wp_safe_redirect(admin_url('admin.php?page=poller&poller_notice=' . $notice));
        exit;
    }

    public function render_list(): void
    {
        $this->guard();
        $polls = $this->repo->list_polls();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Polls', 'poller'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=poller-edit')); ?>" class="page-title-action"><?php esc_html_e('Add poll', 'poller'); ?></a>
            <hr class="wp-header-end">
            <?php $this->notices(); ?>
            <?php if (count($polls) === 0) : ?>
                <p><?php esc_html_e('No polls yet. Add a poll to get a code for the room.', 'poller'); ?></p>
            <?php else : ?>
                <table class="widefat striped poller-admin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Poll', 'poller'); ?></th>
                            <th><?php esc_html_e('Code', 'poller'); ?></th>
                            <th><?php esc_html_e('Choices', 'poller'); ?></th>
                            <th><?php esc_html_e('Status', 'poller'); ?></th>
                            <th><?php esc_html_e('Votes', 'poller'); ?></th>
                            <th><?php esc_html_e('Created', 'poller'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($polls as $poll) : ?>
                            <tr>
                                <td class="poller-admin-question">
                                    <strong><a href="<?php echo esc_url($this->edit_url((int) $poll['id'])); ?>"><?php echo esc_html($poll['title']); ?></a></strong>
                                    <?php if ($poll['title'] !== $poll['question']) : ?>
                                        <div class="description"><?php echo esc_html($poll['question']); ?></div>
                                    <?php endif; ?>
                                    <div class="row-actions">
                                        <span><a href="<?php echo esc_url($this->edit_url((int) $poll['id'])); ?>"><?php esc_html_e('Edit', 'poller'); ?></a> | </span>
                                        <span><a href="<?php echo esc_url(Plugin::board_url($poll['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Room display', 'poller'); ?></a> | </span>
                                        <span><a href="<?php echo esc_url(Plugin::poll_url($poll['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open poll', 'poller'); ?></a> | </span>
                                        <span>
                                            <form class="poller-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <?php wp_nonce_field('poller_status_' . (int) $poll['id']); ?>
                                                <input type="hidden" name="action" value="poller_set_status">
                                                <input type="hidden" name="poll_id" value="<?php echo esc_attr((string) $poll['id']); ?>">
                                                <input type="hidden" name="status" value="<?php echo $poll['status'] === 'open' ? 'closed' : 'open'; ?>">
                                                <button type="submit" class="button-link"><?php echo $poll['status'] === 'open' ? esc_html__('Close', 'poller') : esc_html__('Reopen', 'poller'); ?></button>
                                            </form>
                                            |
                                        </span>
                                        <span>
                                            <form class="poller-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-poller-confirm="<?php echo esc_attr__('Delete this poll and its votes?', 'poller'); ?>">
                                                <?php wp_nonce_field('poller_delete_poll_' . (int) $poll['id']); ?>
                                                <input type="hidden" name="action" value="poller_delete_poll">
                                                <input type="hidden" name="poll_id" value="<?php echo esc_attr((string) $poll['id']); ?>">
                                                <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'poller'); ?></button>
                                            </form>
                                        </span>
                                    </div>
                                </td>
                                <td><span class="poller-admin-code-small"><?php echo esc_html($poll['code']); ?></span></td>
                                <td>
                                    <?php
                                    echo esc_html($poll['kind'] === 'image' ? __('Pictures', 'poller') : __('Text', 'poller'));
                                    echo esc_html(', ');
                                    echo esc_html($poll['selection'] === 'multi' ? __('more than one', 'poller') : __('one', 'poller'));
                                    ?>
                                </td>
                                <td><?php echo $poll['status'] === 'open' ? esc_html__('Open', 'poller') : esc_html__('Closed', 'poller'); ?></td>
                                <td><?php echo esc_html((string) (int) $poll['ballots']); ?></td>
                                <td><?php echo esc_html($this->local_time((string) $poll['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_edit(): void
    {
        $this->guard();

        if ($this->old) {
            $input = $this->old;
            $poll = $input['id'] ? $this->repo->get_poll((int) $input['id']) : null;
        } else {
            $id = absint($_GET['poll'] ?? 0);
            $poll = $id ? $this->repo->get_poll($id) : null;
            if ($id && !$poll) {
                wp_die(esc_html__('That poll does not exist.', 'poller'), '', ['response' => 404]);
            }
            $input = $poll ? $this->input_from_poll($poll) : $this->blank_input();
        }

        $locked = $poll && $this->repo->ballot_count((int) $poll['id']) > 0;
        $choices = $input['choices'];
        if (!$locked) {
            while (count($choices) < 2) {
                $choices[] = ['label' => '', 'image_id' => 0];
            }
        }
        $prompt = $this->image_preview((int) $input['image_id']);
        ?>
        <div class="wrap">
            <h1><?php echo $poll ? esc_html__('Edit poll', 'poller') : esc_html__('Add poll', 'poller'); ?></h1>
            <?php $this->notices(); ?>
            <?php if ($this->error !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html($this->error); ?></p></div>
            <?php endif; ?>
            <?php if ($poll) : ?>
                <p class="poller-admin-code" id="poller-code-text"><?php echo esc_html($poll['code']); ?></p>
                <p>
                    <button type="button" class="button" data-poller-copy="poller-code-text"><?php esc_html_e('Copy code', 'poller'); ?></button>
                    <a class="button" href="<?php echo esc_url(Plugin::board_url($poll['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Room display', 'poller'); ?></a>
                    <a class="button" href="<?php echo esc_url(Plugin::poll_url($poll['code'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open poll', 'poller'); ?></a>
                </p>
                <p>
                    <label for="poller-link"><?php esc_html_e('Poll link', 'poller'); ?></label><br>
                    <input type="text" id="poller-link" class="large-text" readonly value="<?php echo esc_attr(Plugin::poll_url($poll['code'])); ?>">
                    <button type="button" class="button" data-poller-copy="poller-link"><?php esc_html_e('Copy link', 'poller'); ?></button>
                </p>
                <p class="description">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s is the page where someone types a poll code. */
                        __('People can scan the room display, or open %s and enter the code.', 'poller'),
                        $this->human_join()
                    ));
                    ?>
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=poller-edit')); ?>">
                <?php wp_nonce_field('poller_save_poll'); ?>
                <input type="hidden" name="poll_id" value="<?php echo esc_attr((string) (int) $input['id']); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="poller-title"><?php esc_html_e('Name', 'poller'); ?></label></th>
                        <td>
                            <input type="text" id="poller-title" name="title" class="regular-text" maxlength="160" value="<?php echo esc_attr($input['title']); ?>">
                            <p class="description"><?php esc_html_e('Shown only to you, so you can tell polls apart.', 'poller'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="poller-question"><?php esc_html_e('Question', 'poller'); ?></label></th>
                        <td>
                            <textarea id="poller-question" name="question" class="large-text" rows="3" maxlength="500" required><?php echo esc_textarea($input['question']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Picture', 'poller'); ?></th>
                        <td>
                            <input type="hidden" id="poller-image-id" name="image_id" value="<?php echo esc_attr((string) (int) $input['image_id']); ?>">
                            <span id="poller-prompt-preview">
                                <?php if ($prompt !== '') : ?>
                                    <img src="<?php echo esc_url($prompt); ?>" alt="">
                                <?php endif; ?>
                            </span>
                            <button type="button" class="button" id="poller-pick-prompt"><?php esc_html_e('Choose picture', 'poller'); ?></button>
                            <button type="button" class="button-link" id="poller-clear-prompt" <?php echo $prompt === '' ? 'hidden' : ''; ?>><?php esc_html_e('Remove picture', 'poller'); ?></button>
                            <p class="description"><?php esc_html_e('Optional. Shown with the question, for a poll about an image.', 'poller'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Choices', 'poller'); ?></th>
                        <td>
                            <?php if ($locked) : ?>
                                <p><?php echo esc_html($input['kind'] === 'image' ? __('Picture choices', 'poller') : __('Text choices', 'poller')); ?>,
                                    <?php echo esc_html($input['selection'] === 'multi' ? __('more than one', 'poller') : __('one', 'poller')); ?></p>
                                <ul class="poller-admin-locked">
                                    <?php foreach ($choices as $choice) : ?>
                                        <li>
                                            <?php $thumb = $input['kind'] === 'image' ? $this->image_preview((int) $choice['image_id']) : ''; ?>
                                            <?php if ($thumb !== '') : ?>
                                                <img src="<?php echo esc_url($thumb); ?>" alt="">
                                            <?php endif; ?>
                                            <?php echo esc_html($choice['label'] !== '' ? $choice['label'] : __('Picture', 'poller')); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="description"><?php esc_html_e('People have already voted, so the choices stay as they are. You can still edit the question, the picture, and whether the poll is open.', 'poller'); ?></p>
                            <?php else : ?>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('Choice type', 'poller'); ?></legend>
                                    <label><input type="radio" name="kind" value="text" <?php checked($input['kind'], 'text'); ?>> <?php esc_html_e('Text', 'poller'); ?></label>
                                    &nbsp;
                                    <label><input type="radio" name="kind" value="image" <?php checked($input['kind'], 'image'); ?>> <?php esc_html_e('Pictures', 'poller'); ?></label>
                                </fieldset>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php esc_html_e('How many answers', 'poller'); ?></legend>
                                    <label><input type="radio" name="selection" value="single" <?php checked($input['selection'], 'single'); ?>> <?php esc_html_e('One choice', 'poller'); ?></label>
                                    &nbsp;
                                    <label><input type="radio" name="selection" value="multi" <?php checked($input['selection'], 'multi'); ?>> <?php esc_html_e('More than one', 'poller'); ?></label>
                                </fieldset>
                                <div id="poller-choices" data-kind="<?php echo esc_attr($input['kind']); ?>" data-max="12" data-placeholder-text="<?php echo esc_attr__('Choice', 'poller'); ?>" data-placeholder-image="<?php echo esc_attr__('Caption, optional', 'poller'); ?>">
                                    <?php foreach ($choices as $choice) : ?>
                                        <?php $thumb = $this->image_preview((int) $choice['image_id']); ?>
                                        <div class="poller-admin-choice">
                                            <input type="text" class="regular-text" name="choice_label[]" maxlength="160" value="<?php echo esc_attr($choice['label']); ?>" placeholder="<?php echo esc_attr($input['kind'] === 'image' ? __('Caption, optional', 'poller') : __('Choice', 'poller')); ?>">
                                            <input type="hidden" class="poller-choice-image-id" name="choice_image[]" value="<?php echo esc_attr((string) (int) $choice['image_id']); ?>">
                                            <button type="button" class="button poller-pick-choice"><?php esc_html_e('Picture', 'poller'); ?></button>
                                            <button type="button" class="button-link-delete poller-remove-choice"><?php esc_html_e('Remove', 'poller'); ?></button>
                                            <span class="poller-choice-preview"><?php if ($thumb !== '') : ?><img src="<?php echo esc_url($thumb); ?>" alt=""><?php endif; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p><button type="button" class="button" id="poller-add-choice"><?php esc_html_e('Add choice', 'poller'); ?></button></p>
                                <template id="poller-choice-template">
                                    <div class="poller-admin-choice">
                                        <input type="text" class="regular-text" name="choice_label[]" maxlength="160" value="" placeholder="<?php echo esc_attr__('Choice', 'poller'); ?>">
                                        <input type="hidden" class="poller-choice-image-id" name="choice_image[]" value="0">
                                        <button type="button" class="button poller-pick-choice"><?php esc_html_e('Picture', 'poller'); ?></button>
                                        <button type="button" class="button-link-delete poller-remove-choice"><?php esc_html_e('Remove', 'poller'); ?></button>
                                        <span class="poller-choice-preview"></span>
                                    </div>
                                </template>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="poller-status"><?php esc_html_e('Status', 'poller'); ?></label></th>
                        <td>
                            <select id="poller-status" name="status">
                                <option value="open" <?php selected($input['status'], 'open'); ?>><?php esc_html_e('Open', 'poller'); ?></option>
                                <option value="closed" <?php selected($input['status'], 'closed'); ?>><?php esc_html_e('Closed', 'poller'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button($poll ? __('Save poll', 'poller') : __('Create poll', 'poller')); ?>
            </form>
        </div>
        <?php
    }

    private function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage polls.', 'poller'), '', ['response' => 403]);
        }
    }

    private function notices(): void
    {
        if (!isset($_GET['poller_notice'])) {
            return;
        }
        $key = sanitize_key(wp_unslash((string) $_GET['poller_notice']));
        $map = [
            'saved' => __('Poll saved.', 'poller'),
            'deleted' => __('Poll deleted.', 'poller'),
            'closed' => __('Poll closed.', 'poller'),
            'opened' => __('Poll opened.', 'poller'),
            'kept' => __('People voted while you were editing, so the choices were kept.', 'poller'),
        ];
        if (!isset($map[$key])) {
            return;
        }
        $class = $key === 'kept' ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($map[$key]) . '</p></div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function posted_poll(): array
    {
        $labels = (isset($_POST['choice_label']) && is_array($_POST['choice_label'])) ? wp_unslash($_POST['choice_label']) : [];
        $images = (isset($_POST['choice_image']) && is_array($_POST['choice_image'])) ? wp_unslash($_POST['choice_image']) : [];
        $count = max(count($labels), count($images));
        $choices = [];
        for ($i = 0; $i < $count; $i++) {
            $label_raw = $labels[$i] ?? '';
            $image_raw = $images[$i] ?? 0;
            if (!is_scalar($label_raw) || !is_scalar($image_raw)) {
                continue;
            }
            $label = sanitize_text_field((string) $label_raw);
            $image_id = absint($image_raw);
            if ($label === '' && $image_id === 0) {
                continue;
            }
            $choices[] = [
                'label' => $label,
                'image_id' => $image_id,
            ];
        }

        $kind = (isset($_POST['kind']) && $_POST['kind'] === 'image') ? 'image' : 'text';
        $selection = (isset($_POST['selection']) && $_POST['selection'] === 'multi') ? 'multi' : 'single';
        $status = (isset($_POST['status']) && $_POST['status'] === 'closed') ? 'closed' : 'open';

        return [
            'id' => absint($_POST['poll_id'] ?? 0),
            'title' => sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? ''))),
            'question' => sanitize_textarea_field(wp_unslash((string) ($_POST['question'] ?? ''))),
            'image_id' => absint($_POST['image_id'] ?? 0),
            'kind' => $kind,
            'selection' => $selection,
            'status' => $status,
            'choices' => $choices,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validate(array $input, bool $locked): ?string
    {
        if ($input['question'] === '') {
            return __('Write the question people will see.', 'poller');
        }
        if ($this->length($input['question']) > 500) {
            return __('Keep the question under 500 characters.', 'poller');
        }
        if ($this->length($input['title']) > 160) {
            return __('Keep the name under 160 characters.', 'poller');
        }
        if ($input['image_id'] && !wp_attachment_is_image($input['image_id'])) {
            return __('The question picture needs to be an image.', 'poller');
        }
        if ($locked) {
            return null;
        }
        if (count($input['choices']) < 2) {
            return __('Add at least two choices.', 'poller');
        }
        if (count($input['choices']) > 12) {
            return __('A poll can have up to 12 choices.', 'poller');
        }
        foreach ($input['choices'] as $choice) {
            if ($this->length($choice['label']) > 160) {
                return __('Keep each choice under 160 characters.', 'poller');
            }
            if ($input['kind'] === 'text' && $choice['label'] === '') {
                return __('Each choice needs a label.', 'poller');
            }
            if ($input['kind'] === 'image' && (!$choice['image_id'] || !wp_attachment_is_image($choice['image_id']))) {
                return __('Each picture choice needs an image.', 'poller');
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array{label: string, image_id: int}>
     */
    private function choice_rows(array $input): array
    {
        $rows = [];
        foreach ($input['choices'] as $choice) {
            $rows[] = [
                'label' => $choice['label'],
                'image_id' => $input['kind'] === 'image' ? (int) $choice['image_id'] : 0,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function title_or_question(array $input): string
    {
        if ($input['title'] !== '') {
            return $input['title'];
        }
        $flat = trim((string) preg_replace('/\s+/', ' ', $input['question']));
        if (function_exists('mb_substr')) {
            return mb_substr($flat, 0, 80);
        }
        return substr($flat, 0, 80);
    }

    /**
     * @param array<string, mixed> $poll
     * @return array<string, mixed>
     */
    private function input_from_poll(array $poll): array
    {
        $choices = [];
        foreach ($this->repo->get_choices((int) $poll['id']) as $choice) {
            $choices[] = [
                'label' => $choice['label'],
                'image_id' => $choice['image_id'],
            ];
        }
        return [
            'id' => (int) $poll['id'],
            'title' => (string) $poll['title'],
            'question' => (string) $poll['question'],
            'image_id' => (int) $poll['image_id'],
            'kind' => (string) $poll['kind'],
            'selection' => (string) $poll['selection'],
            'status' => (string) $poll['status'],
            'choices' => $choices,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank_input(): array
    {
        return [
            'id' => 0,
            'title' => '',
            'question' => '',
            'image_id' => 0,
            'kind' => 'text',
            'selection' => 'single',
            'status' => 'open',
            'choices' => [],
        ];
    }

    private function image_preview(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $url = wp_get_attachment_image_url($id, 'thumbnail');
        return $url ? $url : '';
    }

    private function edit_url(int $id): string
    {
        return admin_url('admin.php?page=poller-edit&poll=' . $id);
    }

    private function local_time(string $gmt): string
    {
        return get_date_from_gmt($gmt, get_option('date_format') . ' ' . get_option('time_format'));
    }

    private function human_join(): string
    {
        $human = preg_replace('#^https?://#', '', untrailingslashit(Plugin::join_url()));
        return $human ? $human : Plugin::join_url();
    }

    private function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}
