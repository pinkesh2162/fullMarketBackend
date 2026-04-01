<?php

namespace App\Repositories;

use App\Models\Claim;
use App\Mail\ClaimMail;
use Illuminate\Support\Facades\Mail;

class ClaimRepository
{
    /**
     * @var Claim
     */
    protected $model;

    /**
     * ClaimRepository constructor.
     *
     * @param Claim $model
     */
    public function __construct(Claim $model)
    {
        $this->model = $model;
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
        $claim = $this->model->create($data);

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
        Mail::queue($adminEmail)->send(new ClaimMail($claim));

        return $claim;
    }
}
