<?php

namespace App\Repositories;

use App\Models\Claim;
use App\Mail\ClaimMail;
use Illuminate\Container\Container as Application;
use Illuminate\Support\Facades\Mail;

class ClaimRepository extends BaseRepository
{
    /**
     * @param  Application  $app
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [
            'user_id',
            'listing_id',
            'status',
        ];
    }

    /**
     * @return string
     */
    public function model()
    {
        return Claim::class;
    }

    /**
     * Create a new claim/remove ad request.
     *
     * @param array $data
     * @param array|null $images
     * @return Claim
     */
    public function createClaim(array $data, $images = null)
    {
        $claim = $this->create($data);

        if ($images && is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof \Illuminate\Http\UploadedFile) {
                    $claim->addMedia($image)
                          ->toMediaCollection(Claim::CLAIM_IMAGES, config('app.media_disc', 'public'));
                }
            }
        }

        // Notify owner
        $adminEmail = config('app.admin_email');
        Mail::to($adminEmail)->send(new ClaimMail($claim));

        return $claim;
    }
}
