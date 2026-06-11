<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponDiscountAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a coupon is properly assigned to a sale via pivot table.
     */
    public function test_coupon_assignment_creates_pivot_record(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $coupon = Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'TEST_COUPON',
            'name' => 'Test Coupon',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'number' => 'TEST001',
            'status' => 'paid',
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'sold_at' => now(),
        ]);

        // Simulate attaching coupon to sale
        $sale->coupons()->attach($coupon->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 50.00,
        ]);

        // Verify the pivot record exists
        $this->assertTrue($sale->coupons()->where('coupon_id', $coupon->id)->exists());

        // Verify the relationship works from both sides
        $this->assertCount(1, $sale->coupons);
        $this->assertCount(1, $coupon->sales);
        $this->assertEquals(50.00, $sale->coupons->first()->pivot->amount_applied);
    }

    /**
     * Test that a discount rule is properly assigned to a sale via pivot table.
     */
    public function test_discount_rule_assignment_creates_pivot_record(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $rule = DiscountRule::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Rule',
            'code' => 'TEST_RULE',
            'type' => 'fixed',
            'value' => 50,
            'scope' => 'cart',
            'is_active' => true,
        ]);
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'number' => 'TEST002',
            'status' => 'paid',
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'sold_at' => now(),
        ]);

        // Simulate attaching discount rule to sale
        $sale->discountRules()->attach($rule->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 75.00,
        ]);

        // Verify the pivot record exists
        $this->assertTrue($sale->discountRules()->where('discount_rule_id', $rule->id)->exists());

        // Verify the relationship works from both sides
        $this->assertCount(1, $sale->discountRules);
        $this->assertCount(1, $rule->sales);
        $this->assertEquals(75.00, $sale->discountRules->first()->pivot->amount_applied);
    }

    /**
     * Test that multiple coupons can be assigned to a single sale.
     */
    public function test_multiple_coupons_per_sale(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $coupon1 = Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'COUPON_1',
            'name' => 'Coupon 1',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);
        $coupon2 = Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'COUPON_2',
            'name' => 'Coupon 2',
            'type' => 'fixed',
            'value' => 25,
            'is_active' => true,
        ]);
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'number' => 'TEST003',
            'status' => 'paid',
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'sold_at' => now(),
        ]);

        $sale->coupons()->attach($coupon1->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 50.00,
        ]);
        $sale->coupons()->attach($coupon2->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 75.00,
        ]);

        $this->assertCount(2, $sale->coupons);
        $this->assertTrue($sale->coupons->contains($coupon1));
        $this->assertTrue($sale->coupons->contains($coupon2));
    }

    /**
     * Test that a coupon can be assigned to multiple sales.
     */
    public function test_coupon_assigned_to_multiple_sales(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $coupon = Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'MULTI_SALE_COUPON',
            'name' => 'Multi Sale Coupon',
            'type' => 'percent',
            'value' => 15,
            'is_active' => true,
        ]);
        $sale1 = Sale::create([
            'tenant_id' => $tenant->id,
            'number' => 'TEST004',
            'status' => 'paid',
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'sold_at' => now(),
        ]);
        $sale2 = Sale::create([
            'tenant_id' => $tenant->id,
            'number' => 'TEST005',
            'status' => 'paid',
            'subtotal_amount' => 200,
            'discount_amount' => 0,
            'total_amount' => 200,
            'sold_at' => now(),
        ]);

        $sale1->coupons()->attach($coupon->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 50.00,
        ]);
        $sale2->coupons()->attach($coupon->id, [
            'tenant_id' => $tenant->id,
            'amount_applied' => 75.00,
        ]);

        $this->assertCount(2, $coupon->sales);
        $this->assertTrue($coupon->sales->contains($sale1));
        $this->assertTrue($coupon->sales->contains($sale2));
    }
}

