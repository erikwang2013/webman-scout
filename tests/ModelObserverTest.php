<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\ModelObserver;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mockery;
use PHPUnit\Framework\TestCase;

class ObserverSoftDeleteStub extends Model
{
    use SoftDeletes;
}

class ObserverPlainStub extends Model
{
}

class ModelObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        ModelObserver::enableSyncingFor(ObserverPlainStub::class);
        ModelObserver::enableSyncingFor(ObserverSoftDeleteStub::class);
        $this->addToAssertionCount(1); // count Mockery expectation verifications
    }

    protected function setConfig(array $params): void
    {
        ScoutConfig::setSource(static function (string $key, $default = null) use ($params) {
            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });
    }

    protected function mockModel(string $class = ObserverPlainStub::class)
    {
        return Mockery::mock($class);
    }

    protected function scoutConfig(array $extra = []): array
    {
        return array_merge(['driver' => 'null', 'after_commit' => false, 'soft_delete' => false], $extra);
    }

    public function testConstructorReadsAfterCommitConfig(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig(['after_commit' => true])]);

        $observer = new ModelObserver();
        $this->assertTrue($observer->afterCommit);

        $this->setConfig(['scout' => $this->scoutConfig(['after_commit' => false])]);
        $observer = new ModelObserver();
        $this->assertFalse($observer->afterCommit);
    }

    public function testSavedMakesSearchableModel(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('searchIndexShouldBeUpdated')->once()->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->once()->andReturn(true);
        $model->shouldReceive('searchable')->once();

        (new ModelObserver())->saved($model);
    }

    public function testSavedSkipsWhenIndexShouldNotBeUpdated(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('searchIndexShouldBeUpdated')->once()->andReturn(false);
        $model->shouldReceive('searchable')->never();

        (new ModelObserver())->saved($model);
    }

    public function testSavedRemovesNonSearchableModelThatWasSearchable(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('searchIndexShouldBeUpdated')->once()->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->once()->andReturn(false);
        $model->shouldReceive('wasSearchableBeforeUpdate')->once()->andReturn(true);
        $model->shouldReceive('unsearchable')->once();

        (new ModelObserver())->saved($model);
    }

    public function testSavedSkipsWhenSyncingDisabled(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        ModelObserver::disableSyncingFor(get_class($model));

        $model->shouldReceive('searchable')->never();

        (new ModelObserver())->saved($model);

        ModelObserver::enableSyncingFor(get_class($model));
    }

    public function testDeletedRemovesFromSearch(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('wasSearchableBeforeDelete')->once()->andReturn(true);
        $model->shouldReceive('unsearchable')->once();

        (new ModelObserver())->deleted($model);
    }

    public function testDeletedSkipsWhenNotSearchableBeforeDelete(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('wasSearchableBeforeDelete')->once()->andReturn(false);
        $model->shouldReceive('unsearchable')->never();

        (new ModelObserver())->deleted($model);
    }

    public function testDeletedWithSoftDeletesAndSoftDeleteConfigForcesSaved(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig(['soft_delete' => true])]);
        $model = $this->mockModel(ObserverSoftDeleteStub::class);
        $model->shouldReceive('wasSearchableBeforeDelete')->once()->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->once()->andReturn(true);
        $model->shouldReceive('searchable')->once();
        $model->shouldReceive('unsearchable')->never();

        (new ModelObserver())->deleted($model);
    }

    public function testForceDeletedRemovesFromSearch(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('unsearchable')->once();

        (new ModelObserver())->forceDeleted($model);
    }

    public function testRestoredForcesSaved(): void
    {
        $this->setConfig(['scout' => $this->scoutConfig()]);
        $model = $this->mockModel();
        $model->shouldReceive('shouldBeSearchable')->once()->andReturn(true);
        $model->shouldReceive('searchable')->once();

        (new ModelObserver())->restored($model);
    }

    public function testSyncingDisabledForWithClassStringAndObject(): void
    {
        $this->assertFalse(ModelObserver::syncingDisabledFor(ObserverPlainStub::class));
        $this->assertFalse(ModelObserver::syncingDisabledFor(new ObserverPlainStub()));

        ModelObserver::disableSyncingFor(ObserverPlainStub::class);
        $this->assertTrue(ModelObserver::syncingDisabledFor(ObserverPlainStub::class));
        $this->assertTrue(ModelObserver::syncingDisabledFor(new ObserverPlainStub()));

        ModelObserver::enableSyncingFor(ObserverPlainStub::class);
        $this->assertFalse(ModelObserver::syncingDisabledFor(ObserverPlainStub::class));
    }
}
