<?php
namespace Opencart\Admin\Controller\Extension\Epay\Payment;

class EpayEpic extends \Opencart\System\Engine\Controller {
    public function index(): void {
        $this->load->language('extension/epay/payment/epay_epic');

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit']     = $this->language->get('text_edit');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/epay/payment/epay_epic', $data));
    }

    public function install(): void {
        $this->load->model('setting/extension');
        $this->model_setting_extension->install('payment', 'epay_epic');

        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/epay/payment/epay_epic');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/epay/payment/epay_epic');
    }

    public function uninstall(): void {
        $this->load->model('setting/extension');
        $this->model_setting_extension->uninstall('payment', 'epay_epic');
    }
}
