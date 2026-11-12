<?php

use Paymenter\Extensions\Gateways\CoinsPay\Lib\Decision;
use Paymenter\Extensions\Gateways\CoinsPay\Lib\Text;
use PHPUnit\Framework\TestCase;

/**
 * CoinsPay invoice state → what happens to the Paymenter invoice.
 */
final class DecisionTest extends TestCase
{
    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'number' => 12, 'status' => 'open', 'test' => false,
            'currency' => 'USD', 'amount' => '100', 'amount_paid' => '0', 'amount_pending' => '0', 'amount_due' => '100',
            'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false, 'payments' => [],
        ];
    }

    private function paid(array $overrides = []): array
    {
        return $this->invoice($overrides + ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0',
            'payments' => [['deposit_id' => 'd1', 'status' => 'confirmed', 'chain' => 'tron', 'asset' => 'USDT', 'amount' => '100', 'tx_hash' => 'abcdef1234567890abcdef']]]);
    }

    private function decide(array $invoice, array $state = []): array
    {
        return Decision::decide($invoice, $state + ['status' => 'pending', 'remaining' => '100.00', 'requested' => '100.00', 'credited' => '', 'recorded' => '', 'user_changed' => false, 'currency_changed' => false, 'test_admin' => false, 'apply_test' => false], new Text('en'));
    }

    public function test_a_paid_invoice_credits_what_it_was_made_for()
    {
        $decision = $this->decide($this->paid());
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount']);
        $this->assertStringContainsString('100 USD', $decision['note']);
        $this->assertStringContainsString('tron', $decision['note']);
    }

    public function test_late_manual_and_overpaid_are_noted()
    {
        $decision = $this->decide($this->paid(['paid_late' => true, 'paid_manually' => true, 'amount_overpaid' => '5']));
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount'], 'the excess stays in CoinsPay');
        $this->assertStringContainsString('after the CoinsPay invoice expired', $decision['note']);
        $this->assertStringContainsString('manually', $decision['note']);
        $this->assertStringContainsString('Overpaid by 5 USD', $decision['note']);
    }

    public function test_a_credited_amount_is_not_credited_again()
    {
        $this->assertSame(Decision::NONE, $this->decide($this->paid(), ['credited' => '100.00', 'recorded' => 'succeeded'])['action']);
        // Even when the invoice is paid meanwhile: nothing new came.
        $this->assertSame(Decision::NONE, $this->decide($this->paid(), ['credited' => '100.00', 'recorded' => 'succeeded', 'status' => 'paid'])['action']);
    }

    public function test_money_for_an_invoice_that_no_longer_waits_goes_to_the_admin()
    {
        foreach (['cancelled', 'paid', 'draft'] as $status) {
            $decision = $this->decide($this->paid(), ['status' => $status]);
            $this->assertSame(Decision::HOLD, $decision['action'], $status);
            $this->assertStringContainsString('"' . $status . '"', $decision['note']);
        }
        $this->assertSame(Decision::HOLD, $this->decide($this->invoice(['status' => 'expired', 'amount_paid' => '40']), ['status' => 'cancelled'])['action']);
    }

    public function test_an_invoice_of_another_client_now_goes_to_the_admin()
    {
        $decision = $this->decide($this->paid(), ['user_changed' => true]);
        $this->assertSame(Decision::HOLD, $decision['action']);
        $this->assertStringContainsString('another client', $decision['note']);
    }

    public function test_a_test_payment_counts_only_for_an_admin_and_when_allowed()
    {
        $test = $this->paid(['test' => true]);
        $this->assertSame(Decision::HOLD, $this->decide($test)['action']);
        $this->assertSame(Decision::HOLD, $this->decide($test, ['apply_test' => true])['action']);
        $this->assertSame(Decision::LOG, $this->decide($test, ['test_admin' => true])['action']);
        $this->assertSame(Decision::CREDIT, $this->decide($test, ['test_admin' => true, 'apply_test' => true])['action']);
    }

    public function test_money_on_its_way_is_shown_as_processing()
    {
        $pending = $this->decide($this->invoice(['amount_pending' => '100']));
        $this->assertSame(Decision::PROCESSING, $pending['action']);
        $this->assertSame('100', $pending['amount']);

        $partial = $this->decide($this->invoice(['amount_paid' => '40', 'amount_due' => '60']));
        $this->assertSame(Decision::PROCESSING, $partial['action']);
        $this->assertSame('40', $partial['amount']);
        $this->assertStringContainsString('40 USD received, 60 USD left', $partial['note']);

        // Not on an invoice that no longer waits, and not when something is counted already.
        $this->assertSame(Decision::LOG, $this->decide($this->invoice(['amount_pending' => '100']), ['status' => 'cancelled'])['action']);
        $this->assertSame(Decision::LOG, $this->decide($this->invoice(['amount_pending' => '10']), ['credited' => '40.00'])['action']);
        $this->assertSame(Decision::NONE, $this->decide($this->invoice())['action']);
    }

    public function test_an_expired_or_cancelled_invoice_credits_the_part_received()
    {
        foreach (['expired', 'cancelled'] as $status) {
            $decision = $this->decide($this->invoice(['status' => $status, 'amount_paid' => '40', 'amount_pending' => '10']));
            $this->assertSame(Decision::CREDIT, $decision['action'], $status);
            $this->assertSame('40', $decision['amount'], 'only confirmed money');
            $this->assertStringContainsString('40 of 100 USD', str_replace(' USD of', ' of', $decision['note']));
        }
    }

    public function test_the_part_received_is_counted_without_the_fees_the_client_paid_on_top()
    {
        // "Client pays the fee": the CoinsPay invoice asks 103 for 100 of the invoice.
        $decision = $this->decide($this->invoice(['status' => 'expired', 'amount' => '103', 'amount_paid' => '51.5']));
        $this->assertSame('50', $decision['amount']);
        $this->assertSame('33.33', $this->decide($this->invoice(['status' => 'expired', 'amount' => '103', 'amount_paid' => '34.33']))['amount']);
        // Paid in full with the fee: the whole invoice amount.
        $this->assertSame('100', $this->decide($this->invoice(['status' => 'expired', 'amount' => '103', 'amount_paid' => '103']))['amount']);
    }

    public function test_a_late_payment_raises_the_part_credited_before()
    {
        $decision = $this->decide($this->paid(['paid_late' => true]), ['credited' => '40.00', 'recorded' => 'succeeded']);
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount']);
        // The same part again is nothing new.
        $this->assertSame(Decision::NONE, $this->decide($this->invoice(['status' => 'expired', 'amount_paid' => '40']), ['credited' => '40.00', 'recorded' => 'succeeded'])['action']);
    }

    public function test_a_closed_invoice_without_money()
    {
        $this->assertSame(Decision::LOG, $this->decide($this->invoice(['status' => 'expired']))['action']);
        $this->assertSame(Decision::FAILED, $this->decide($this->invoice(['status' => 'expired']), ['recorded' => 'processing'])['action']);
        $this->assertSame(Decision::LOG, $this->decide($this->invoice(['status' => 'expired', 'amount_pending' => '100']), ['recorded' => 'processing'])['action'], 'still on its way');
    }

    public function test_a_changed_currency_of_the_invoice_goes_to_the_admin()
    {
        $decision = $this->decide($this->paid(), ['currency_changed' => true]);
        $this->assertSame(Decision::HOLD, $decision['action']);
        $this->assertTrue($decision['attention']);
        $this->assertStringContainsString('currency of the invoice changed', $decision['note']);
    }

    public function test_never_more_than_the_invoice_still_owes()
    {
        // The admin lowered the invoice to 80 after the client had paid 100.
        $decision = $this->decide($this->paid(), ['remaining' => '80.00']);
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('80', $decision['amount']);
        $this->assertTrue($decision['attention']);
        $this->assertStringContainsString('20 USD more stays in CoinsPay', $decision['note']);

        // A late rest of an expired part: 40 credited, the rest of the invoice was paid by a new
        // CoinsPay invoice of 60, but the invoice still waits (a fee, say) for 5.
        $late = $this->decide($this->paid(['paid_late' => true]), ['credited' => '40.00', 'recorded' => 'succeeded', 'remaining' => '5.00']);
        $this->assertSame('45', $late['amount']);

        // Nothing owed at all: held, not credited.
        $none = $this->decide($this->paid(), ['remaining' => '0.00']);
        $this->assertSame(Decision::HOLD, $none['action']);
        $this->assertStringContainsString('nothing is left to pay', $none['note']);
    }

    public function test_a_plain_payment_needs_no_admin()
    {
        $this->assertFalse($this->decide($this->paid())['attention']);
        $this->assertTrue($this->decide($this->paid(['amount_overpaid' => '5']))['attention']);
    }

    public function test_a_test_payment_shows_as_processing_only_when_it_would_count()
    {
        $test = $this->invoice(['test' => true, 'amount_pending' => '100']);
        $this->assertSame(Decision::LOG, $this->decide($test, ['test_admin' => true])['action']);
        $this->assertSame(Decision::PROCESSING, $this->decide($test, ['test_admin' => true, 'apply_test' => true])['action']);
    }

    public function test_a_currency_without_cents_credits_what_the_invoice_wanted()
    {
        // JPY: the CoinsPay invoice asked 1078 for 1078.32 due on the invoice.
        $paid = $this->decide($this->paid(['amount' => '1078', 'amount_paid' => '1078', 'currency' => 'JPY']), ['requested' => '1078.32', 'remaining' => '1078.32']);
        $this->assertSame('1078.32', $paid['amount']);
        $half = $this->decide($this->invoice(['status' => 'expired', 'amount' => '1078', 'amount_paid' => '539', 'currency' => 'JPY']), ['requested' => '1078.32', 'remaining' => '1078.32']);
        $this->assertSame('539.16', $half['amount']);
    }

    public function test_the_fingerprint_follows_the_state_that_matters()
    {
        $a = Decision::fingerprint($this->paid());
        $this->assertSame($a, Decision::fingerprint($this->paid(['pay_url' => 'changed'])));
        $this->assertNotSame($a, Decision::fingerprint($this->paid(['paid_manually' => true])));
        $this->assertNotSame($a, Decision::fingerprint($this->invoice()));
    }
}
