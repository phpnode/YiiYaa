# YAA - Yet Another Abstraction
## Note: This is work in progress, it's not suitable for use in production yet
YAA is an additional abstraction layer for Yii that aggregates a number of child models into a clean single model
that is easy to cache, this is known as an Aggregate Model.

YAA consists of Aggregate Models that each represent a core object in the system, aggregate models are a special kind
of model that consist purely of attributes loaded from one or more child models.
Aggregate objects know how to load their own data from the cache and the database based on a primary key,
for example you'd load a specific user with User::load(1), or a list of users with User::load(array(1,2,3)).
They can smooth over inconsistent naming formats with custom attribute names, so $member->mem_id becomes $user->id etc.
These attribute names can be either specified as part of the aggregate's mapping or with getters and setters.
Another important part of aggregate models is versioning, all aggregate models have their own version number that is
incremented whenever a field on the aggregate changes. This makes it much easier for us to deal with full page and
fragment caching, as we can use this version number as a dependency for each fragment and page that we cache,
and if the dependency is invalid, we just regenerate the fragment or page, using the cached aggregate, incurring very minimal overhead.


In addition to aggregate models, we also have the AggregateChildBehavior class. As the name implies this behavior is added to
the "children" of the aggregates, e.g. if you stored your users' address details in a model called "Address", then this
 model would be a child of the User aggregate class. When a mapped field on an aggregate child changes, it updates the
relevant field on the aggregate model that the model belongs to, and recaches the aggregate model, incrementing the version number.

There are several advantages to the Aggregate system:
	1. Allows a simple, consistent way of getting and setting commonly used data.
	2. Allows write through caching, objects are always loaded from the cache where possible
	3. Works alongside your existing code, you can start using YAA gradually.
	4. Makes it far easier to switch to a NOSQL storage solution in future.
	5. Provides an identity map that ensures the same object is only loaded once per request
	6. Model relations are cached efficiently


# Getting Started

In the following examples, we'll assume you have the following active record classes that you wish to aggregate:

	Member 		- represents the basic details for a member
	Address 	- the member's address details. Member has one Address
	IsoCountry	- the country the member resides in. Address has one IsoCountry

and you wish to aggregate the above into a new class called User and a new class called Country.
<pre lang="php">
class User extends AAggregateModel
{
    /**
     * @var integer the duration to cache for, 0 means forever
     */
    public static $cacheDuration = 0;

    /**
     * Declares the models that are being aggregated.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "ModelName", // will load a model with the same PK as the aggregate ID
     *      "AnotherModelName" => "someField" // will load models which have the field "someField" set to the aggregate's ID
     *  )
     * </pre>
     */
    public static function models()
    {
        return array(
            "Member",
            "Address" => "mem_id",
        );
    }

    /**
     * Assembles the new model instances that should be
     * populated and saved when creating new aggregates.
     * @return array of models, modelName => model instance
     */
    public static function assemble()
    {
        $member = new Member();
        $address = new Address();
        return array(
            "Member" => $member,
            "Address" => $address,
        );
    }

    /**
     * Gets the mapping of attributes for this model.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "cleanedUpAttributeName" => array("ModelName", "attribute_name"),
     *      "relationAttributeName" => array("ModelName", "relationName.field_name"),
     *  )
     * </pre>
     */
    public static function mapping()
    {
        return array(
        	// get the following from the "Member" model
            "id" => array("Member", "mem_id"),
            "isActive" => array("Member", "active"),
            "firstName" => array("Member", "fname"),
            "lastName" => array("Member", "lname"),
            "isOnline" => array("Member", "is_online"),
            "about" => array("Member", "about"),
            "email" => array("Members","email"),

			// get the following from the "Address" model
            "addressLine1" => array("Address", "line1"),
            "addressLine2" => array("Address", "line2"),
            "city" => array("Address", "city"),
            "postcode" => array("Address", "postcode"),
            "countryId" => array("Address", "country")

        );
    }

    /**
     * @return array the default values for the aggregate
     */
    public function defaults()
    {
        return array(
            "countryId" => "GB",
        );
    }


    /**
     * @return array the aggregate relations
     */
    public function relations()
    {
        return array(
            "country" => array(
                self::BELONGS_TO,
                "Country",
                "attribute" => "countryId"
            ),
        );
    }

    /**
     * Gets the name of the user's country
     * @return string the country name
     */
    public function getCountryName()
    {
        return $this->country->name;
    }
}

/**
 * Represents a country
 */
class Country extends AAggregateModel
{
    /**
     * Declares the models that are being aggregated.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "ModelName", // will load a model with the same PK as the aggregate ID
     *      "AnotherModelName" => "someField" // will load models which have the field "someField" set to the aggregate's ID
     *  )
     * </pre>
     */
    public static function models()
    {
        return array(
            "IsoCountry"
        );
    }

    /**
     * Gets the mapping of attributes for this model.
     * This should be an array in the format:
     * <pre>
     *  array(
     *      "cleanedUpAttributeName" => array("ModelName", "attribute_name"),
     *      "relationAttributeName" => array("ModelName", "relationName.field_name"),
     *      "someReadOnlyAttribute" => array("modelName", "readOnlyAttributeName", true),
     *  )
     * </pre>
     */
    public static function mapping()
    {
        return array(
            "id" => array("IsoCountry", "iso"),
            "name" => array("IsoCountry", "printable_name"),
            "currency" => array("IsoCountry", "currency"),
            "iso3" => array("IsoCountry", "iso3"),
            "latitude" => array("IsoCountry", "latitude"),
            "longitude" => array("IsoCountry", "longitude"),
        );
    }

    /**
     * Assembles the new model instances that should be
     * populated and saved when creating new aggregates.
     * @return array of models, modelName => model instance
     */
    public static function assemble()
    {
        return array(
            "IsoCountry" => new IsoCountry()
        );
    }

}

</pre>

With the above, it's possible to load and save user details with the following syntax:

<pre lang="php">
$user = User::load(1); // load the user with the member id of 1, this object is now cached forever
echo $user->firstName." ".$user->lastName."\n";
echo "Country:".$user->country->name."\n";

// separately load the country again (actually returns a reference to the same $user->country object)
$country = Country::load($user->countryId);
$country->name = "Test Country";
$country->save();

$user->country->name == $country->name;

$user->firstName = "Test";
$user->save(); // the "Member" model will be used to save this attribute

$user->addressLine1 = "123 Fake Street";
$user->save(); // the "Address" model will be used to save this attribute
$user->save(); // no attributes are dirty so does nothing

$member = Member::model()->findByPk(1);
$member->lname = "fake";
$member->save();
$user->lastName == "fake"; // automatically updates the aggregate

</pre>

