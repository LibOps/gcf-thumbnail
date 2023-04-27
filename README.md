# gcf-thumbnail

PHP Google Cloud Function (GCF) to create a thumbnail when an image is uploaded to a Google Cloud Storage (GCS) bucket.

Meant to be deployed as a gen2 GCF subscribed to a GCS bucket that needs automatic thumbnail creation when a file is uploaded to the bucket.

The GCF should have an Eventarc trigger on the bucket's `google.cloud.storage.object.v1.finalized` event.
